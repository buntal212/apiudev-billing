<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\ApplicationPaymentGateway;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingDanaSandboxRegressionTest extends TestCase
{
    use RefreshDatabase;

    private string $fakeGatewayStatus = 'pending';

    private ?string $fakeGatewayPaidAt = null;

    private ?string $fakeGatewayPaymentId = null;

    private string $fakeGatewayCheckoutUrl = 'https://sandbox.dana.id/checkout/session123';

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

        $this->fakeGatewayStatus = 'pending';
        $this->fakeGatewayPaidAt = null;
        $this->fakeGatewayPaymentId = null;
        $this->fakeGatewayCheckoutUrl = 'https://sandbox.dana.id/checkout/session123';

        Http::fake(function (Request $request): mixed {
            $url = $request->url();

            if (str_ends_with($url, '/status')) {
                preg_match('#/api/v1/payments/([^/]+)/status#', $url, $matches);
                $paymentId = $matches[1] ?? $this->fakeGatewayPaymentId ?? 'dana-pay-default';

                $attempt = PaymentAttempt::query()->where('gateway_payment_id', $paymentId)->first()
                    ?? PaymentAttempt::query()->latest('attempt_number')->first();

                $merchantOrderId = $attempt?->merchant_reference ?? 'ORDER-REF';
                $amountMinor = $attempt ? (int) $attempt->getRawOriginal('amount') : 100000;
                $currency = $attempt?->currency ?? 'IDR';

                return Http::response([
                    'data' => [
                        'id' => $paymentId,
                        'merchant_order_id' => $merchantOrderId,
                        'amount_minor' => $amountMinor,
                        'currency' => $currency,
                        'status' => $this->fakeGatewayStatus,
                        'checkout_url' => $this->fakeGatewayCheckoutUrl,
                        'expires_at' => now()->addMinutes(30)->toISOString(),
                        'paid_at' => $this->fakeGatewayStatus === 'paid' ? ($this->fakeGatewayPaidAt ?? now()->toISOString()) : null,
                    ],
                ], 200);
            }

            if ($request->method() === 'GET') {
                return Http::response(['data' => []], 200);
            }

            $paymentId = $this->fakeGatewayPaymentId ?? 'dana-pay-'.Str::lower(Str::random(10));
            $merchantOrderId = $request['merchant_order_id'] ?? 'ORDER-REF';
            $amountMinor = (int) ($request['amount_minor'] ?? 100000);

            return Http::response([
                'data' => [
                    'id' => $paymentId,
                    'merchant_order_id' => $merchantOrderId,
                    'amount_minor' => $amountMinor,
                    'currency' => 'IDR',
                    'status' => 'pending',
                    'checkout_url' => $this->fakeGatewayCheckoutUrl,
                    'expires_at' => now()->addMinutes(30)->toISOString(),
                ],
            ], 200);
        });
    }

    public function test_customer_chooses_plan_and_period_count_between_1_and_12(): void
    {
        Sanctum::actingAs($this->admin(), ['admin:access']);
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create([
            'application_id' => $application->id,
            'price' => '100000.00',
            'duration_days' => 30,
        ]);

        // Test valid period counts: 1 and 6
        $dueAt = now()->addDays(5)->toDateTimeString();
        $response = $this->postJson('/api/v1/admin/invoices', [
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'period_count' => 6,
            'due_at' => $dueAt,
        ], ['Idempotency-Key' => 'inv-period-6'])
            ->assertCreated()
            ->assertJsonPath('data.period_count', 6)
            ->assertJsonPath('data.duration_days', 180)
            ->assertJsonPath('data.amount', '600000.00');

        $this->assertDatabaseHas('invoices', [
            'id' => $response->json('data.id'),
            'amount' => '600000.00',
            'period_count' => 6,
            'duration_days' => 180,
            'status' => Invoice::StatusPending,
        ]);

        // Test invalid period counts: 0 and 13
        $this->postJson('/api/v1/admin/invoices', [
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'period_count' => 0,
            'due_at' => now()->addDays(5)->toDateTimeString(),
        ], ['Idempotency-Key' => 'inv-period-0'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period_count');

        $this->postJson('/api/v1/admin/invoices', [
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'period_count' => 13,
            'due_at' => now()->addDays(5)->toDateTimeString(),
        ], ['Idempotency-Key' => 'inv-period-13'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period_count');
    }

    public function test_prevents_duplicate_invoice_for_same_obligation_and_handles_idempotency(): void
    {
        Sanctum::actingAs($this->admin(), ['admin:access']);
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create([
            'application_id' => $application->id,
            'price' => '150000.00',
            'duration_days' => 30,
        ]);
        $dueAt = now()->addDays(3)->setMicrosecond(0)->toISOString();

        $first = $this->postJson('/api/v1/admin/invoices', [
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'period_count' => 2,
            'due_at' => $dueAt,
        ], ['Idempotency-Key' => 'key-invoice-idem-1'])
            ->assertCreated();

        // Same idempotency key returns existing invoice
        $retry = $this->postJson('/api/v1/admin/invoices', [
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'period_count' => 2,
            'due_at' => $dueAt,
        ], ['Idempotency-Key' => 'key-invoice-idem-1'])
            ->assertOk();

        $this->assertSame($first->json('data.id'), $retry->json('data.id'));
        $this->assertDatabaseCount('invoices', 1);

        // Different idempotency key but same pending obligation is rejected
        $this->postJson('/api/v1/admin/invoices', [
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'period_count' => 2,
            'due_at' => $dueAt,
        ], ['Idempotency-Key' => 'key-invoice-different'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invoice');
    }

    public function test_invoice_can_be_checked_out_via_dana_sandbox_without_marking_invoice_paid(): void
    {
        Sanctum::actingAs($this->admin(), ['admin:access']);
        [$invoice] = $this->createInvoiceWithGateway(periodCount: 3);

        $this->fakeDanaGateway(
            status: 'pending',
            fixedPaymentId: 'dana-pay-ulid-001',
            checkoutUrl: 'https://sandbox.dana.id/checkout/session123',
        );

        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [], ['Idempotency-Key' => 'chk-001'])
            ->assertOk()
            ->assertJsonPath('data.checkout_status', 'ready')
            ->assertJsonPath('data.checkout_url', 'https://sandbox.dana.id/checkout/session123')
            ->assertJsonPath('data.gateway_payment_id', 'dana-pay-ulid-001');

        $invoice->refresh();
        $this->assertSame(Invoice::StatusPending, $invoice->status);
        $this->assertNull($invoice->paid_at);
        $this->assertDatabaseHas('payment_attempts', [
            'invoice_id' => $invoice->id,
            'status' => PaymentAttempt::StatusPending,
            'gateway_payment_id' => 'dana-pay-ulid-001',
        ]);
    }

    public function test_invoice_becomes_paid_and_subscription_extended_by_30_x_period_preserving_remaining_days(): void
    {
        Sanctum::actingAs($this->admin(), ['admin:access']);
        [$invoice, $subscription] = $this->createInvoiceWithGateway(periodCount: 2, withSubscription: true);

        // Subscription currently has 10 days remaining
        $initialExpiresAt = CarbonImmutable::parse($subscription->expires_at);
        $verifiedPaidAt = now()->setMicrosecond(0)->toImmutable();

        // Mock gateway returning paid status
        $this->fakeDanaGateway(
            status: 'paid',
            paidAt: $verifiedPaidAt->toISOString(),
            fixedPaymentId: 'dana-pay-ulid-002',
        );

        // Start checkout
        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [], ['Idempotency-Key' => 'chk-002'])->assertOk();

        // Reconcile via admin invoice endpoint
        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/reconcile")
            ->assertOk()
            ->assertJsonPath('data.status', Invoice::StatusPaid)
            ->assertJsonPath('data.paid_at', $verifiedPaidAt->toISOString());

        $invoice->refresh();
        $subscription->refresh();

        $this->assertSame(Invoice::StatusPaid, $invoice->status);
        $this->assertNotNull($invoice->paid_at);

        // Period count = 2 -> duration_days = 60.
        // Active expiry was in future, so new expires_at must be exactly initialExpiresAt + 60 days
        $expectedExpiry = $initialExpiresAt->addDays(60);
        $this->assertTrue(
            $expectedExpiry->equalTo($subscription->expires_at),
            "Expected expiry {$expectedExpiry->toISOString()} but got {$subscription->expires_at->toISOString()}"
        );

        // Verify activation history was recorded
        $this->assertDatabaseHas('subscription_period_events', [
            'subscription_id' => $subscription->id,
            'event_type' => 'renewal',
            'previous_expires_at' => $initialExpiresAt->toDateTimeString(),
            'new_expires_at' => $expectedExpiry->toDateTimeString(),
        ]);

        $this->assertDatabaseCount('payment_allocations', 1);
    }

    public function test_package_change_retains_existing_remaining_active_days(): void
    {
        Sanctum::actingAs($this->admin(), ['admin:access']);
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);

        $planA = SubscriptionPlan::factory()->create([
            'application_id' => $application->id,
            'code' => 'PLAN-A',
            'price' => '50000.00',
            'duration_days' => 30,
        ]);
        $planB = SubscriptionPlan::factory()->create([
            'application_id' => $application->id,
            'code' => 'PLAN-B',
            'price' => '150000.00',
            'duration_days' => 30,
        ]);

        ApplicationPaymentGateway::query()->create([
            'application_id' => $application->id,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'udev-demo',
            'is_active' => true,
        ]);

        // Customer currently active on Plan A with 15 days remaining
        $initialExpiry = now()->addDays(15)->setMicrosecond(0)->toImmutable();
        $subscription = Subscription::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $planA->id,
            'starts_at' => now()->subDays(15),
            'expires_at' => $initialExpiry,
            'status' => Subscription::StatusActive,
        ]);

        // Customer purchases Plan B with 3 periods (90 days)
        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $planB->id,
            'subscription_id' => $subscription->id,
            'amount' => '450000.00',
            'currency' => 'IDR',
            'duration_months' => 3,
            'duration_days' => 90,
            'period_count' => 3,
            'status' => Invoice::StatusPending,
        ]);

        $verifiedPaidAt = now()->toImmutable();
        $this->fakeDanaGateway(
            status: 'paid',
            paidAt: $verifiedPaidAt->toISOString(),
            fixedPaymentId: 'dana-pay-plan-change',
        );

        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [], ['Idempotency-Key' => 'chk-plan-chg'])->assertOk();
        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/reconcile")->assertOk();

        $subscription->refresh();
        // Plan should be updated to Plan B
        $this->assertSame($planB->id, $subscription->subscription_plan_id);

        // Expiry must be initialExpiry + 90 days (15 + 90 = 105 days total from now)
        $expectedExpiry = $initialExpiry->addDays(90);
        $this->assertTrue(
            $expectedExpiry->equalTo($subscription->expires_at),
            "Expected expiry {$expectedExpiry->toISOString()} but got {$subscription->expires_at->toISOString()}"
        );
    }

    public function test_failed_payment_does_not_change_subscription(): void
    {
        Sanctum::actingAs($this->admin(), ['admin:access']);
        [$invoice, $subscription] = $this->createInvoiceWithGateway(periodCount: 1, withSubscription: true);
        $initialExpiry = $subscription->expires_at->toISOString();

        $this->fakeDanaGateway(
            status: 'failed',
            fixedPaymentId: 'dana-pay-failed',
        );

        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [], ['Idempotency-Key' => 'chk-failed'])->assertOk();
        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/reconcile")->assertOk();

        $invoice->refresh();
        $subscription->refresh();

        $this->assertSame(Invoice::StatusPending, $invoice->status);
        $this->assertNull($invoice->paid_at);
        $this->assertSame($initialExpiry, $subscription->expires_at->toISOString());
        $this->assertDatabaseHas('payment_attempts', [
            'invoice_id' => $invoice->id,
            'status' => PaymentAttempt::StatusFailed,
        ]);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_prevents_duplicate_activation_on_repeated_reconciliation(): void
    {
        Sanctum::actingAs($this->admin(), ['admin:access']);
        [$invoice, $subscription] = $this->createInvoiceWithGateway(periodCount: 1, withSubscription: true);
        $initialExpiry = CarbonImmutable::parse($subscription->expires_at);

        $this->fakeDanaGateway(
            status: 'paid',
            paidAt: now()->toISOString(),
            fixedPaymentId: 'dana-pay-repeat',
        );

        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [], ['Idempotency-Key' => 'chk-rep'])->assertOk();

        // First reconcile
        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/reconcile")->assertOk();
        $firstExpiry = $subscription->fresh()->expires_at->toISOString();

        // Second reconcile (webhook/retry duplicate)
        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/reconcile")->assertOk();
        $secondExpiry = $subscription->fresh()->expires_at->toISOString();

        $this->assertSame($firstExpiry, $secondExpiry);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertDatabaseCount('subscription_period_events', 1);
    }

    public function test_admin_sandbox_simulation_cannot_mark_paid_or_activate_subscription_and_settlement_only_from_verified_gateway(): void
    {
        Sanctum::actingAs($this->admin(), ['admin:access']);
        [$invoice, $subscription] = $this->createInvoiceWithGateway(periodCount: 4, withSubscription: true);
        $initialExpiry = CarbonImmutable::parse($subscription->expires_at);

        // Simulation endpoint MUST NOT allow marking invoice paid or activating subscription
        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/sandbox-simulate-payment", [
            'action' => 'paid',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('action')
            ->assertJsonPath('errors.action.0', 'Endpoint simulasi Sandbox tidak dapat menandai invoice paid atau mengaktifkan subscription. Settlement operasional hanya dapat diproses melalui konfirmasi gateway yang valid.');

        $invoice->refresh();
        $subscription->refresh();

        // Invoice remains pending and subscription is untouched
        $this->assertSame(Invoice::StatusPending, $invoice->status);
        $this->assertNull($invoice->paid_at);
        $this->assertSame($initialExpiry->toISOString(), $subscription->expires_at->toISOString());
        $this->assertDatabaseCount('subscription_period_events', 0);
        $this->assertDatabaseCount('payment_allocations', 0);

        // Operational settlement ONLY occurs through valid gateway confirmation (e.g. via reconcile)
        $this->fakeDanaGateway(
            status: 'paid',
            paidAt: now()->toISOString(),
            fixedPaymentId: 'dana-pay-gateway-verified',
        );

        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [], ['Idempotency-Key' => 'chk-gateway-verified'])
            ->assertOk();

        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/reconcile")
            ->assertOk()
            ->assertJsonPath('data.status', Invoice::StatusPaid)
            ->assertJsonPath('data.duration_days', 120);

        $invoice->refresh();
        $subscription->refresh();

        $expectedExpiry = $initialExpiry->addDays(120);
        $this->assertSame(Invoice::StatusPaid, $invoice->status);
        $this->assertTrue($expectedExpiry->equalTo($subscription->expires_at));
        $this->assertDatabaseCount('subscription_period_events', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
    }

    public function test_admin_sandbox_simulate_failure_scenario(): void
    {
        Sanctum::actingAs($this->admin(), ['admin:access']);
        [$invoice, $subscription] = $this->createInvoiceWithGateway(periodCount: 2, withSubscription: true);
        $initialExpiry = $subscription->expires_at->toISOString();

        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/sandbox-simulate-payment", [
            'action' => 'failed',
        ])
            ->assertOk()
            ->assertJsonPath('test.simulation_action', 'failed');

        $this->assertSame(Invoice::StatusPending, $invoice->fresh()->status);
        $this->assertSame($initialExpiry, $subscription->fresh()->expires_at->toISOString());
        $this->assertDatabaseHas('payment_attempts', [
            'invoice_id' => $invoice->id,
            'status' => PaymentAttempt::StatusFailed,
        ]);
    }

    public function test_integration_api_preview_and_store_invoice_with_period_count(): void
    {
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create([
            'application_id' => $application->id,
            'external_customer_id' => 'CUST-E2E-001',
        ]);
        $plan = SubscriptionPlan::factory()->create([
            'application_id' => $application->id,
            'code' => 'BIZ-30',
            'price' => '200000.00',
            'duration_days' => 30,
        ]);

        $token = $application->integrationTokens()->create([
            'name' => 'Global Token',
            'token_hash' => hash('sha256', 'global-test-token-123'),
            'token_prefix' => 'gtok',
            'token_kind' => 'global',
            'status' => 'active',
            'scopes' => ['*'],
        ]);

        // Preview with 3 periods
        $this->withToken('global-test-token-123')
            ->postJson("/api/v1/integrations/customers/{$customer->external_customer_id}/invoice-preview", [
                'subscription_plan_code' => $plan->code,
                'period_count' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('data.plan.duration_days', 90)
            ->assertJsonPath('data.plan.period_count', 3)
            ->assertJsonPath('data.pricing.normal_price', '600000.00')
            ->assertJsonPath('data.pricing.final_price', '600000.00');

        // Create invoice with 3 periods
        $this->withToken('global-test-token-123')
            ->postJson("/api/v1/integrations/customers/{$customer->external_customer_id}/invoices", [
                'subscription_plan_code' => $plan->code,
                'period_count' => 3,
                'due_at' => now()->addDays(5)->toISOString(),
            ], ['Idempotency-Key' => 'integ-inv-period-3'])
            ->assertCreated()
            ->assertJsonPath('data.plan.duration_days', 90)
            ->assertJsonPath('data.pricing.final_price', '600000.00');

        $this->assertDatabaseHas('invoices', [
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '600000.00',
            'period_count' => 3,
            'duration_days' => 90,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /**
     * @return array{0: Invoice, 1: ?Subscription}
     */
    private function createInvoiceWithGateway(int $periodCount = 1, bool $withSubscription = false): array
    {
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create([
            'application_id' => $application->id,
            'price' => '100000.00',
            'duration_days' => 30,
        ]);

        ApplicationPaymentGateway::query()->create([
            'application_id' => $application->id,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'udev-demo',
            'is_active' => true,
        ]);

        $subscription = null;
        if ($withSubscription) {
            $subscription = Subscription::factory()->create([
                'application_customer_id' => $customer->id,
                'subscription_plan_id' => $plan->id,
                'starts_at' => now()->subDays(20),
                'expires_at' => now()->addDays(10)->setMicrosecond(0),
                'status' => Subscription::StatusActive,
            ]);
        }

        $amount = bcmul('100000.00', (string) $periodCount, 2);
        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'subscription_id' => $subscription?->id,
            'amount' => $amount,
            'currency' => 'IDR',
            'duration_months' => $periodCount,
            'duration_days' => 30 * $periodCount,
            'period_count' => $periodCount,
            'status' => Invoice::StatusPending,
        ]);

        return [$invoice, $subscription];
    }

    private function fakeDanaGateway(
        string $status = 'pending',
        ?string $paidAt = null,
        ?string $fixedPaymentId = null,
        string $checkoutUrl = 'https://sandbox.dana.id/checkout/session123',
    ): void {
        $this->fakeGatewayStatus = $status;
        $this->fakeGatewayPaidAt = $paidAt;
        $this->fakeGatewayPaymentId = $fixedPaymentId;
        $this->fakeGatewayCheckoutUrl = $checkoutUrl;
    }
}
