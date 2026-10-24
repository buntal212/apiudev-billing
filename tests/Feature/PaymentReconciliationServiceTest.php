<?php

namespace Tests\Feature;

use App\Models\ApplicationPaymentGateway;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentReconciliationRun;
use App\Models\Subscription;
use App\Services\PaymentReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.dana_gateway', [
            'environment' => 'sandbox',
            'base_urls' => ['sandbox' => 'https://gateway.test'],
            'timeout' => 8,
            'connect_timeout' => 3,
            'merchant_api_keys' => ['sandbox' => ['udev-demo' => 'test-api-key']],
            'merchants' => ['sandbox' => ['udev-demo' => ['name' => 'UDEV Demo', 'is_active' => true]]],
        ]);
        config()->set('services.dana_reconciliation', [
            'max_attempts' => 3,
            'batch_limit' => 25,
            'base_backoff_seconds' => 15,
            'max_backoff_seconds' => 900,
            'processing_timeout_seconds' => 120,
        ]);
    }

    public function test_paid_gateway_payment_settles_invoice_and_renews_subscription(): void
    {
        [$attempt, $subscription] = $this->attemptWithSubscription();
        $previousExpiry = $subscription->expires_at->toImmutable();
        Http::fake($this->gatewayResponse($attempt, 'paid'));

        $result = app(PaymentReconciliationService::class)->reconcile($attempt);

        $this->assertSame(PaymentReconciliationRun::StatusPaid, $result->status);
        $this->assertSame('paid', $result->run?->gateway_status);
        $this->assertSame(Invoice::StatusPaid, $attempt->invoice->fresh()->status);
        $this->assertSame(PaymentAttempt::StatusSucceeded, $attempt->fresh()->status);
        $this->assertTrue($subscription->fresh()->expires_at->equalTo($previousExpiry->addDays(30)));
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertDatabaseCount('subscription_period_events', 1);
        Http::assertSentCount(1);
    }

    public function test_pending_gateway_payment_is_not_settled_and_is_scheduled_for_later(): void
    {
        $attempt = $this->attempt();
        Http::fake($this->gatewayResponse($attempt, 'pending'));

        $result = app(PaymentReconciliationService::class)->reconcile($attempt);
        $second = app(PaymentReconciliationService::class)->reconcile($attempt->fresh());

        $this->assertSame(PaymentReconciliationRun::StatusPending, $result->status);
        $this->assertSame('skipped', $second->status);
        $this->assertSame(PaymentAttempt::StatusPending, $attempt->fresh()->status);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertNotNull($result->retryAfterAt);
    }

    public function test_batch_only_selects_attempts_whose_retry_window_is_due(): void
    {
        $future = $this->attempt();
        $this->reconciliationRun($future, [
            'status' => PaymentReconciliationRun::StatusPending,
            'retry_after_at' => now()->addMinute(),
        ]);

        $processing = $this->attempt();
        $this->reconciliationRun($processing, [
            'status' => PaymentReconciliationRun::StatusProcessing,
            'started_at' => now()->subSeconds(30),
        ]);

        $exhausted = $this->attempt();
        $this->reconciliationRun($exhausted, [
            'attempt_number' => 3,
            'status' => PaymentReconciliationRun::StatusRetryable,
            'retry_after_at' => now()->subMinute(),
        ]);

        $due = $this->attempt();
        $this->reconciliationRun($due, [
            'status' => PaymentReconciliationRun::StatusRetryable,
            'retry_after_at' => now()->subMinute(),
        ]);

        $new = $this->attempt();
        $responses = [
            (string) $due->gateway_payment_id => $this->gatewayPayment($due, 'pending'),
            (string) $new->gateway_payment_id => $this->gatewayPayment($new, 'pending'),
        ];
        $requestedPaymentIds = [];
        Http::fake(function (HttpRequest $request) use (&$requestedPaymentIds, $responses): mixed {
            $path = trim((string) parse_url($request->url(), PHP_URL_PATH), '/');
            $segments = explode('/', $path);
            $paymentId = $segments[count($segments) - 2] ?? null;
            $requestedPaymentIds[] = $paymentId;

            return Http::response(['data' => $responses[$paymentId]], 200);
        });

        $processed = app(PaymentReconciliationService::class)->reconcilePending(10);

        $this->assertSame(2, $processed);
        $this->assertSame([
            (string) $new->gateway_payment_id,
            (string) $due->gateway_payment_id,
        ], $requestedPaymentIds);
        $this->assertDatabaseCount('payment_reconciliation_runs', 6);
    }

    public function test_new_attempt_is_not_starved_by_an_old_attempt_waiting_for_backoff(): void
    {
        $old = $this->attempt();
        $this->reconciliationRun($old, [
            'status' => PaymentReconciliationRun::StatusRetryable,
            'retry_after_at' => now()->addMinutes(5),
        ]);
        $new = $this->attempt();
        Http::fake($this->gatewayResponse($new, 'pending'));

        $processed = app(PaymentReconciliationService::class)->reconcilePending(1);

        $this->assertSame(1, $processed);
        $this->assertDatabaseHas('payment_reconciliation_runs', [
            'payment_attempt_id' => $new->getKey(),
            'attempt_number' => 1,
        ]);
        $this->assertDatabaseCount('payment_reconciliation_runs', 2);
        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), (string) $new->gateway_payment_id));
    }

    public function test_fresh_processing_run_cannot_be_claimed_twice(): void
    {
        $attempt = $this->attempt();
        $this->reconciliationRun($attempt, [
            'status' => PaymentReconciliationRun::StatusProcessing,
            'started_at' => now()->subSeconds(30),
        ]);
        Http::fake($this->gatewayResponse($attempt, 'paid'));

        $result = app(PaymentReconciliationService::class)->reconcile($attempt);

        $this->assertSame('skipped', $result->status);
        $this->assertDatabaseCount('payment_reconciliation_runs', 1);
        Http::assertNothingSent();
    }

    public function test_exhausted_or_failed_runs_are_not_selected_again(): void
    {
        $exhausted = $this->attempt();
        $this->reconciliationRun($exhausted, [
            'attempt_number' => 3,
            'status' => PaymentReconciliationRun::StatusRetryable,
            'retry_after_at' => now()->subMinute(),
        ]);
        $failed = $this->attempt();
        $this->reconciliationRun($failed, [
            'status' => PaymentReconciliationRun::StatusFailed,
            'error_code' => 'gateway_request_rejected',
            'finished_at' => now()->subMinute(),
        ]);
        Http::fake();

        $processed = app(PaymentReconciliationService::class)->reconcilePending(10);

        $this->assertSame(0, $processed);
        Http::assertNothingSent();
        $this->assertSame('skipped', app(PaymentReconciliationService::class)->reconcile($failed)->status);
    }

    public function test_due_attempts_use_their_configured_merchant_credentials(): void
    {
        config()->set('services.dana_gateway.merchant_api_keys', [
            'sandbox' => [
                'udev-demo' => 'merchant-one-key',
                'udev-second' => 'merchant-two-key',
            ],
        ]);
        config()->set('services.dana_gateway.merchants', [
            'sandbox' => [
                'udev-demo' => ['name' => 'UDEV Demo', 'is_active' => true],
                'udev-second' => ['name' => 'UDEV Second', 'is_active' => true],
            ],
        ]);

        $first = $this->attempt(['gateway_merchant_code' => 'udev-demo']);
        $second = $this->attempt(['gateway_merchant_code' => 'udev-second']);
        $responses = [
            (string) $first->gateway_payment_id => $this->gatewayPayment($first, 'pending'),
            (string) $second->gateway_payment_id => $this->gatewayPayment($second, 'pending'),
        ];
        $authorizationByPayment = [];
        Http::fake(function (HttpRequest $request) use (&$authorizationByPayment, $responses): mixed {
            $path = trim((string) parse_url($request->url(), PHP_URL_PATH), '/');
            $segments = explode('/', $path);
            $paymentId = $segments[count($segments) - 2] ?? null;
            $authorizationByPayment[$paymentId] = $request->header('Authorization')[0] ?? null;

            return Http::response(['data' => $responses[$paymentId]], 200);
        });

        $processed = app(PaymentReconciliationService::class)->reconcilePending(2);

        $this->assertSame(2, $processed);
        $this->assertSame('Bearer merchant-one-key', $authorizationByPayment[$first->gateway_payment_id]);
        $this->assertSame('Bearer merchant-two-key', $authorizationByPayment[$second->gateway_payment_id]);
    }

    public function test_failed_and_expired_gateway_statuses_do_not_settle(): void
    {
        foreach (['failed', 'expired'] as $gatewayStatus) {
            $attempt = $this->attempt();
            Http::fake($this->gatewayResponse($attempt, $gatewayStatus));

            $result = app(PaymentReconciliationService::class)->reconcile($attempt);

            $this->assertSame(PaymentReconciliationRun::StatusTerminal, $result->status);
            $this->assertSame(
                $gatewayStatus === 'failed' ? PaymentAttempt::StatusFailed : PaymentAttempt::StatusExpired,
                $attempt->fresh()->status,
            );
        }

        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_duplicate_reconciliation_is_idempotent_and_does_not_call_gateway_again(): void
    {
        $attempt = $this->attemptWithMapping();
        Http::fake($this->gatewayResponse($attempt, 'paid'));

        $first = app(PaymentReconciliationService::class)->reconcile($attempt);
        $second = app(PaymentReconciliationService::class)->reconcile($attempt->fresh());

        $this->assertSame(PaymentReconciliationRun::StatusPaid, $first->status);
        $this->assertSame('skipped', $second->status);
        $this->assertDatabaseCount('payment_reconciliation_runs', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
        Http::assertSentCount(1);
    }

    public function test_gateway_mismatch_is_rejected_without_settlement(): void
    {
        $attempt = $this->attempt();
        Http::fake([
            "https://gateway.test/api/v1/payments/{$attempt->gateway_payment_id}/status" => Http::response([
                'data' => array_merge($this->gatewayPayment($attempt, 'paid'), ['amount_minor' => 1]),
            ]),
        ]);

        $result = app(PaymentReconciliationService::class)->reconcile($attempt);

        $this->assertSame(PaymentReconciliationRun::StatusFailed, $result->status);
        $this->assertSame('gateway_identity_or_amount_mismatch', $result->run?->error_code);
        $this->assertSame(PaymentAttempt::StatusPending, $attempt->fresh()->status);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_rate_limit_retry_after_is_honoured_without_settlement(): void
    {
        $attempt = $this->attempt();
        Http::fake([
            "https://gateway.test/api/v1/payments/{$attempt->gateway_payment_id}/status" => Http::response(
                ['message' => 'rate limited'],
                429,
                ['Retry-After' => '42'],
            ),
        ]);

        $result = app(PaymentReconciliationService::class)->reconcile($attempt);

        $this->assertSame(PaymentReconciliationRun::StatusRetryable, $result->status);
        $this->assertSame('gateway_rate_limited', $result->run?->error_code);
        $this->assertSame(429, $result->run?->http_status);
        $this->assertNotNull($result->retryAfterAt);
        $this->assertTrue($result->retryAfterAt->greaterThan(now()->addSeconds(40)));
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_retry_limit_and_recovery_are_bounded(): void
    {
        config()->set('services.dana_reconciliation.max_attempts', 2);
        $attempt = $this->attempt();
        Http::fake([
            "https://gateway.test/api/v1/payments/{$attempt->gateway_payment_id}/status" => Http::sequence()
                ->push([], 503)
                ->push([], 503)
                ->push(['data' => $this->gatewayPayment($attempt, 'paid')]),
        ]);

        $first = app(PaymentReconciliationService::class)->reconcile($attempt);
        $first->run?->update(['retry_after_at' => now()->subSecond()]);
        $second = app(PaymentReconciliationService::class)->reconcile($attempt->fresh());
        $third = app(PaymentReconciliationService::class)->reconcile($attempt->fresh());

        $this->assertSame(PaymentReconciliationRun::StatusRetryable, $first->status);
        $this->assertSame(PaymentReconciliationRun::StatusFailed, $second->status);
        $this->assertSame('reconciliation_retry_exhausted', $second->run?->error_code);
        $this->assertSame('skipped', $third->status);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_pending_run_can_recover_after_backoff_and_settle(): void
    {
        $attempt = $this->attemptWithMapping();
        Http::fake([
            "https://gateway.test/api/v1/payments/{$attempt->gateway_payment_id}/status" => Http::sequence()
                ->push([], 503)
                ->push(['data' => $this->gatewayPayment($attempt, 'paid')]),
        ]);

        $first = app(PaymentReconciliationService::class)->reconcile($attempt);
        $first->run?->update(['retry_after_at' => now()->subSecond()]);
        $second = app(PaymentReconciliationService::class)->reconcile($attempt->fresh());

        $this->assertSame(PaymentReconciliationRun::StatusPaid, $second->status);
        $this->assertSame(Invoice::StatusPaid, $attempt->invoice->fresh()->status);
        $this->assertDatabaseCount('payment_allocations', 1);
        Http::assertSentCount(2);
    }

    public function test_missing_merchant_mapping_blocks_settlement_and_keeps_invoice_pending(): void
    {
        $attempt = $this->attempt();
        Http::fake($this->gatewayResponse($attempt, 'paid'));

        $result = app(PaymentReconciliationService::class)->reconcile($attempt);

        $this->assertSame(PaymentReconciliationRun::StatusFailed, $result->status);
        $this->assertSame('merchant_mapping_missing', $result->run?->error_code);
        $this->assertSame(Invoice::StatusPending, $attempt->invoice->fresh()->status);
        $this->assertSame(PaymentAttempt::StatusSucceeded, $attempt->fresh()->status);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_verified_gateway_time_is_used_for_delayed_subscription_renewal(): void
    {
        [$attempt, $subscription] = $this->attemptWithSubscription();
        $verifiedAt = now()->subMonths(2)->toImmutable();
        $subscription->update([
            'starts_at' => now()->subMonths(4),
            'expires_at' => now()->subMonth(),
            'status' => Subscription::StatusExpired,
        ]);
        Http::fake($this->gatewayResponse($attempt, 'paid', $verifiedAt->toISOString()));

        $result = app(PaymentReconciliationService::class)->reconcile($attempt);

        $this->assertSame(PaymentReconciliationRun::StatusPaid, $result->status);
        $this->assertSame($verifiedAt->format('Y-m-d H:i:s'), $subscription->fresh()->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame($verifiedAt->addDays(30)->format('Y-m-d H:i:s'), $subscription->fresh()->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_reconciliation_audit_never_stores_gateway_credentials_or_payload(): void
    {
        $attempt = $this->attempt();
        Http::fake([
            "https://gateway.test/api/v1/payments/{$attempt->gateway_payment_id}/status" => Http::response(
                ['secret' => 'do-not-store'],
                503,
            ),
        ]);

        $result = app(PaymentReconciliationService::class)->reconcile($attempt);
        $audit = $result->run?->toArray() ?? [];

        $this->assertArrayNotHasKey('secret', $audit);
        $this->assertArrayNotHasKey('api_key', $audit);
        $this->assertStringNotContainsString('test-api-key', json_encode($audit));
    }

    private function attempt(array $overrides = []): PaymentAttempt
    {
        $attempt = PaymentAttempt::factory()->create(array_merge([
            'amount' => '10000.00',
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusPending,
            'provider' => 'DANA',
            'merchant_reference' => 'INV-RECON-'.Str::upper(Str::random(8)),
            'gateway_merchant_code' => 'udev-demo',
            'gateway_environment' => 'sandbox',
            'gateway_payment_id' => (string) Str::ulid(),
        ], $overrides));
        $attempt->invoice()->update([
            'amount' => $attempt->amount,
            'currency' => $attempt->currency,
        ]);

        return $attempt->fresh('invoice.plan');
    }

    /** @param array<string, mixed> $overrides */
    private function reconciliationRun(PaymentAttempt $attempt, array $overrides = []): PaymentReconciliationRun
    {
        return PaymentReconciliationRun::query()->create(array_merge([
            'payment_attempt_id' => $attempt->getKey(),
            'attempt_number' => 1,
            'status' => PaymentReconciliationRun::StatusProcessing,
            'idempotency_key' => 'reconciliation:test:'.$attempt->getKey().':'.Str::random(8),
            'started_at' => now(),
        ], $overrides));
    }

    private function attemptWithMapping(): PaymentAttempt
    {
        $attempt = $this->attempt();
        ApplicationPaymentGateway::query()->create([
            'application_id' => $attempt->invoice->plan->application_id,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'udev-demo',
            'is_active' => true,
        ]);

        return $attempt;
    }

    /** @return array{0: PaymentAttempt, 1: Subscription} */
    private function attemptWithSubscription(): array
    {
        $attempt = $this->attemptWithMapping();
        $invoice = $attempt->invoice;
        $subscription = Subscription::factory()->create([
            'application_customer_id' => $invoice->application_customer_id,
            'subscription_plan_id' => $invoice->subscription_plan_id,
            'expires_at' => now()->addDays(30),
        ]);
        $invoice->update(['subscription_id' => $subscription->id]);

        return [$attempt->fresh(), $subscription];
    }

    private function gatewayResponse(PaymentAttempt $attempt, string $status, ?string $paidAt = null): array
    {
        return [
            "https://gateway.test/api/v1/payments/{$attempt->gateway_payment_id}/status" => Http::response([
                'data' => $this->gatewayPayment($attempt, $status, $paidAt),
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private function gatewayPayment(PaymentAttempt $attempt, string $status, ?string $paidAt = null): array
    {
        return [
            'id' => $attempt->gateway_payment_id,
            'merchant_order_id' => $attempt->merchant_reference,
            'amount_minor' => 10000,
            'currency' => 'IDR',
            'status' => $status,
            'paid_at' => $status === 'paid' ? ($paidAt ?? now()->toISOString()) : null,
        ];
    }
}
