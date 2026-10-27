<?php

namespace Tests\Feature;

use App\Models\ApplicationPaymentGateway;
use App\Models\CheckoutOutboxMessage;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceCheckoutTest extends TestCase
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
    }

    public function test_admin_can_create_a_valid_checkout_without_changing_invoice_or_subscription_status(): void
    {
        [$invoice, $subscription] = $this->invoiceWithGateway(withSubscription: true);
        $previousExpiry = $subscription->expires_at->toISOString();
        $this->fakeGatewayForNewPayment($invoice, 'gateway-payment-001');

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [], ['Idempotency-Key' => 'checkout-valid-001'])
            ->assertOk()
            ->assertJsonPath('data.invoice_id', $invoice->id)
            ->assertJsonPath('data.gateway_payment_id', 'gateway-payment-001')
            ->assertJsonPath('data.checkout_status', 'ready')
            ->assertJsonPath('data.status', PaymentAttempt::StatusPending)
            ->assertJsonPath('data.checkout_url', 'https://checkout.test/session/001');

        $invoice->refresh();
        $subscription?->refresh();
        $this->assertSame(Invoice::StatusPending, $invoice->status);
        $this->assertSame($previousExpiry, $subscription->fresh()->expires_at->toISOString());
        $this->assertDatabaseHas('checkout_outbox_messages', ['status' => CheckoutOutboxMessage::StatusCompleted]);
    }

    public function test_checkout_rejects_paid_and_expired_invoices(): void
    {
        [$paidInvoice] = $this->invoiceWithGateway(['status' => Invoice::StatusPaid]);
        [$expiredInvoice] = $this->invoiceWithGateway(['due_at' => now()->subSecond()]);

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$paidInvoice->id}/checkout", [], ['Idempotency-Key' => 'checkout-paid-001'])
            ->assertUnprocessable();
        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$expiredInvoice->id}/checkout", [], ['Idempotency-Key' => 'checkout-expired-001'])
            ->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_checkout_rejects_non_idr_and_fractional_rupiah_without_calling_gateway(): void
    {
        [$usdInvoice] = $this->invoiceWithGateway(['currency' => 'USD']);
        [$fractionalInvoice] = $this->invoiceWithGateway(['amount' => '10000.50']);

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$usdInvoice->id}/checkout", [], ['Idempotency-Key' => 'checkout-usd-001'])
            ->assertUnprocessable();
        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$fractionalInvoice->id}/checkout", [], ['Idempotency-Key' => 'checkout-fraction-001'])
            ->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_checkout_requires_a_server_side_merchant_mapping(): void
    {
        $invoice = Invoice::factory()->create();

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [], ['Idempotency-Key' => 'checkout-mapping-001'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invoice');

        Http::assertNothingSent();
    }

    public function test_repeated_checkout_request_returns_the_same_attempt_and_does_not_create_another_gateway_payment(): void
    {
        [$invoice] = $this->invoiceWithGateway();
        $this->fakeGatewayForNewPayment($invoice, 'gateway-payment-002');

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [], ['Idempotency-Key' => 'checkout-repeat-001'])
            ->assertOk();
        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [], ['Idempotency-Key' => 'checkout-repeat-002'])
            ->assertOk()
            ->assertJsonPath('data.gateway_payment_id', 'gateway-payment-002');

        $this->assertDatabaseCount('payment_attempts', 1);
        Http::assertSentCount(2);
    }

    public function test_timeout_is_reconciled_by_merchant_reference_before_a_retry_creates_nothing_new(): void
    {
        [$invoice] = $this->invoiceWithGateway();
        $timedOut = false;
        Http::fake(function (HttpRequest $request) use (&$timedOut): mixed {
            if ($request->method() === 'GET') {
                return Http::response([
                    'data' => $timedOut
                        ? [$this->gatewayPayment(PaymentAttempt::query()->sole(), 'gateway-payment-003')]
                        : [],
                ]);
            }

            $timedOut = true;
            throw new ConnectionException('timeout');
        });

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [], ['Idempotency-Key' => 'checkout-timeout-001'])
            ->assertAccepted()
            ->assertJsonPath('data.checkout_status', 'processing');

        $attempt = PaymentAttempt::query()->sole();
        $outbox = CheckoutOutboxMessage::query()->sole();
        $this->assertSame(CheckoutOutboxMessage::StatusPending, $outbox->status);

        $outbox->update(['available_at' => now()->subSecond()]);
        app(CheckoutService::class)->retryCheckout($attempt);

        $attempt->refresh();
        $this->assertSame(CheckoutOutboxMessage::StatusCompleted, $outbox->fresh()->status);
        $this->assertSame('gateway-payment-003', $attempt->gateway_payment_id);
        $this->assertSame(PaymentAttempt::StatusPending, $attempt->status);
        Http::assertSentCount(2);
    }

    public function test_invalid_gateway_response_and_reference_mismatch_are_not_accepted(): void
    {
        [$invalidInvoice] = $this->invoiceWithGateway();
        Http::fake([
            'https://gateway.test/api/v1/payments*' => Http::response(['data' => []]),
        ]);

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$invalidInvoice->id}/checkout", [], ['Idempotency-Key' => 'checkout-invalid-001'])
            ->assertStatus(502);
        $this->assertDatabaseHas('checkout_outbox_messages', ['status' => CheckoutOutboxMessage::StatusFailed]);

        [$mismatchInvoice] = $this->invoiceWithGateway();
        $this->fakeGatewayForNewPayment($mismatchInvoice, 'gateway-payment-004', merchantOrderId: 'foreign-reference');

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$mismatchInvoice->id}/checkout", [], ['Idempotency-Key' => 'checkout-mismatch-001'])
            ->assertStatus(502);
        $this->assertDatabaseHas('checkout_outbox_messages', ['payment_attempt_id' => 2, 'status' => CheckoutOutboxMessage::StatusFailed]);
    }

    public function test_partial_pending_gateway_payment_is_recovered_with_the_same_attempt_and_outbox_key(): void
    {
        [$invoice] = $this->invoiceWithGateway();
        $postKeys = [];
        Http::fake(function (HttpRequest $request) use (&$postKeys): mixed {
            $attempt = PaymentAttempt::query()->sole();
            if ($request->method() === 'GET') {
                return Http::response(['data' => [[
                    'id' => 'gateway-partial-001',
                    'merchant_order_id' => $attempt->merchant_reference,
                    'amount_minor' => 10000,
                    'currency' => 'IDR',
                    'status' => 'pending',
                    'checkout_url' => null,
                    'expires_at' => null,
                ]]]);
            }

            $postKeys[] = $request->header('Idempotency-Key')[0] ?? null;

            return Http::response(['data' => $this->gatewayPayment($attempt, 'gateway-partial-001')]);
        });

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [], ['Idempotency-Key' => 'checkout-partial-recover-001'])
            ->assertOk()
            ->assertJsonPath('data.gateway_payment_id', 'gateway-partial-001')
            ->assertJsonPath('data.checkout_status', 'ready');

        $attempt = PaymentAttempt::query()->sole();
        $outbox = CheckoutOutboxMessage::query()->sole();
        $this->assertSame('gateway-partial-001', $attempt->gateway_payment_id);
        $this->assertSame(CheckoutOutboxMessage::StatusCompleted, $outbox->status);
        $this->assertSame($outbox->idempotency_key, $postKeys[0]);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertSame(Invoice::StatusPending, $invoice->fresh()->status);
    }

    public function test_admin_can_retry_failed_checkout_with_the_same_attempt_and_idempotency_key(): void
    {
        [$invoice] = $this->invoiceWithGateway();
        $retry = false;
        $observed = [];
        Http::fake(function (HttpRequest $request) use (&$retry, &$observed): mixed {
            if ($request->method() === 'GET') {
                return Http::response(['data' => []]);
            }

            if (! $retry) {
                return Http::response(['data' => []]);
            }

            $attempt = PaymentAttempt::query()->sole();
            $observed[] = [
                'method' => $request->method(),
                'merchant_reference' => $request->data()['merchant_order_id'] ?? null,
                'idempotency_key' => $request->header('Idempotency-Key')[0] ?? null,
            ];

            return Http::response(['data' => $this->gatewayPayment($attempt, 'gateway-retry-001')]);
        });

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [], ['Idempotency-Key' => 'checkout-retry-source-001'])
            ->assertStatus(502);

        $attempt = PaymentAttempt::query()->sole();
        $outbox = CheckoutOutboxMessage::query()->sole();
        $merchantReference = $attempt->merchant_reference;
        $outboxIdempotencyKey = $outbox->idempotency_key;
        $retry = true;

        $retryResponse = $this->actingAdmin()
            ->postJson("/api/v1/admin/payment-attempts/{$attempt->id}/checkout/retry");
        $retryResponse
            ->assertOk()
            ->assertJsonPath('data.id', $attempt->id)
            ->assertJsonPath('data.checkout.status', CheckoutOutboxMessage::StatusCompleted)
            ->assertJsonPath('data.gateway_payment_id', 'gateway-retry-001');

        $attempt->refresh();
        $this->assertSame($merchantReference, $attempt->merchant_reference);
        $this->assertSame($outboxIdempotencyKey, CheckoutOutboxMessage::query()->sole()->idempotency_key);
        $this->assertSame($merchantReference, $observed[0]['merchant_reference']);
        $this->assertSame($outboxIdempotencyKey, $observed[0]['idempotency_key']);

        $this->actingAdmin()
            ->postJson("/api/v1/admin/payment-attempts/{$attempt->id}/checkout/retry")
            ->assertOk()
            ->assertJsonPath('data.gateway_payment_id', 'gateway-retry-001');
        Http::assertSentCount(4);
        $this->assertDatabaseCount('payment_attempts', 1);
    }

    public function test_retry_rejects_attempt_with_foreign_merchant_mapping_or_final_status(): void
    {
        [$invoice] = $this->invoiceWithGateway();
        $attempt = PaymentAttempt::factory()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'provider' => 'dana',
            'merchant_reference' => $invoice->invoice_number.'-A1',
            'gateway_merchant_code' => 'foreign-merchant',
            'gateway_environment' => 'sandbox',
            'amount' => $invoice->amount,
            'currency' => $invoice->currency,
            'status' => PaymentAttempt::StatusInitiated,
        ]);
        CheckoutOutboxMessage::query()->create([
            'payment_attempt_id' => $attempt->id,
            'idempotency_key' => 'dana-checkout-attempt-'.$attempt->id,
            'status' => CheckoutOutboxMessage::StatusFailed,
            'attempts' => 2,
            'error_code' => 'gateway_response_invalid',
        ]);
        Http::fake();

        $this->actingAdmin()
            ->postJson("/api/v1/admin/payment-attempts/{$attempt->id}/checkout/retry")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_attempt');
        Http::assertNothingSent();

        $attempt->update(['status' => PaymentAttempt::StatusSucceeded]);
        $this->actingAdmin()
            ->postJson("/api/v1/admin/payment-attempts/{$attempt->id}/checkout/retry")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_attempt');
        Http::assertNothingSent();
    }

    public function test_endpoint_rejects_frontend_controlled_payment_fields(): void
    {
        [$invoice] = $this->invoiceWithGateway();

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
                'amount' => '1',
                'currency' => 'USD',
                'merchant_code' => 'foreign-merchant',
            ], ['Idempotency-Key' => 'checkout-prohibited-001'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount', 'currency', 'merchant_code']);

        Http::assertNothingSent();
    }

    /** @param array<string, mixed> $attributes
     * @return array{0: Invoice, 1: Subscription|null}
     */
    public function test_admin_can_checkout_with_qris_payment_method(): void
    {
        [$invoice] = $this->invoiceWithGateway();
        Http::fake(function (HttpRequest $request): mixed {
            if ($request->method() === 'GET') {
                return Http::response(['data' => []]);
            }

            $this->assertSame('qris', $request->data()['metadata']['udev_sandbox_payment_method'] ?? null);

            $attempt = PaymentAttempt::query()->sole();

            return Http::response([
                'data' => [
                    'id' => 'gateway-qris-001',
                    'merchant_order_id' => $attempt->merchant_reference,
                    'amount_minor' => 10000,
                    'currency' => 'IDR',
                    'status' => 'pending',
                    'checkout_url' => null,
                    'expires_at' => now()->addMinutes(15)->toISOString(),
                    'metadata' => [
                        'udev_sandbox_payment_instruction' => [
                            'method' => 'qris',
                            'label' => 'QRIS',
                            'type' => 'payment_code',
                            'payment_code' => '00020101021226570011ID.DANA.WWW',
                        ],
                    ],
                ],
            ]);
        });

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
                'payment_method' => 'qris',
            ], ['Idempotency-Key' => 'checkout-qris-001'])
            ->assertOk()
            ->assertJsonPath('data.payment_method', 'qris')
            ->assertJsonPath('data.checkout_status', 'ready')
            ->assertJsonPath('data.qr_content', null)
            ->assertJsonPath('data.payment_code', '00020101021226570011ID.DANA.WWW')
            ->assertJsonPath('data.payment_instruction.type', 'payment_code')
            ->assertJsonPath('data.checkout_url', null);

        $attempt = PaymentAttempt::query()->sole();
        $this->assertSame('qris', $attempt->metadata['payment_method']);
        $this->assertSame('00020101021226570011ID.DANA.WWW', $attempt->metadata['payment_code']);
        $this->assertArrayNotHasKey('qr_content', $attempt->metadata);
    }

    public function test_admin_can_checkout_with_each_supported_virtual_account_method(): void
    {
        foreach (['va_bri' => 'BRI', 'va_pani' => 'PANI', 'va_cimb' => 'CIMB', 'va_btpn' => 'BTPN'] as $method => $bank) {
            [$invoice] = $this->invoiceWithGateway();

            Http::fake(function (HttpRequest $request) use ($method, $bank): mixed {
                if ($request->method() === 'GET') {
                    return Http::response(['data' => []]);
                }

                $attempt = PaymentAttempt::query()->latest('id')->firstOrFail();

                return Http::response([
                    'data' => [
                        'id' => "gateway-{$method}-{$attempt->id}",
                        'merchant_order_id' => $attempt->merchant_reference,
                        'amount_minor' => 10000,
                        'currency' => 'IDR',
                        'status' => 'pending',
                        'checkout_url' => null,
                        'expires_at' => now()->addMinutes(15)->toISOString(),
                        'metadata' => [
                            'udev_sandbox_payment_instruction' => [
                                'method' => $method,
                                'label' => "Virtual Account {$bank}",
                                'type' => 'virtual_account',
                                'payment_code' => "8808{$attempt->id}",
                            ],
                        ],
                    ],
                ]);
            });

            $this->actingAdmin()
                ->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
                    'payment_method' => $method,
                ], ['Idempotency-Key' => "checkout-{$method}-{$invoice->id}"])
                ->assertOk()
                ->assertJsonPath('data.payment_method', $method)
                ->assertJsonPath('data.checkout_status', 'ready')
                ->assertJsonPath('data.payment_instruction.type', 'virtual_account')
                ->assertJsonPath('data.checkout_url', null)
                ->assertJsonStructure(['data' => ['expires_at']]);

            $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();
            $this->assertSame($method, $attempt->metadata['payment_method']);
            $this->assertSame('virtual_account', $attempt->metadata['payment_instruction']['type']);
        }
    }

    public function test_checkout_handles_expired_attempt_and_creates_new_attempt_on_retry(): void
    {
        [$invoice] = $this->invoiceWithGateway();
        $paymentId = 'gateway-payment-first';
        Http::fake(function (HttpRequest $request) use (&$paymentId): mixed {
            if ($request->method() === 'GET') {
                return Http::response(['data' => []]);
            }

            $attempt = PaymentAttempt::query()->latest('attempt_number')->firstOrFail();

            return Http::response([
                'data' => $this->gatewayPayment($attempt, $paymentId),
            ]);
        });

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [], ['Idempotency-Key' => 'chk-exp-001'])
            ->assertOk();

        $attempt1 = PaymentAttempt::query()->firstOrFail();
        $attempt1->update(['checkout_expires_at' => now()->subMinute()]);

        // Second checkout should notice attempt1 is expired and create attempt 2 with new payment
        $paymentId = 'gateway-payment-second';

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [], ['Idempotency-Key' => 'chk-exp-002'])
            ->assertOk()
            ->assertJsonPath('data.gateway_payment_id', 'gateway-payment-second');

        $this->assertSame(PaymentAttempt::StatusExpired, $attempt1->fresh()->status);
        $this->assertDatabaseCount('payment_attempts', 2);
    }

    private function invoiceWithGateway(array $attributes = [], bool $withSubscription = false): array
    {
        $invoice = Invoice::factory()->create([
            'amount' => '10000.00',
            'currency' => 'IDR',
            'due_at' => now()->addDay(),
            ...$attributes,
        ]);
        $subscription = null;

        if ($withSubscription) {
            $subscription = Subscription::factory()->create([
                'application_customer_id' => $invoice->application_customer_id,
                'subscription_plan_id' => $invoice->subscription_plan_id,
                'expires_at' => now()->addDays(30),
            ]);
            $invoice->update(['subscription_id' => $subscription->id]);
        }

        ApplicationPaymentGateway::query()->create([
            'application_id' => $invoice->plan->application_id,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'udev-demo',
            'is_active' => true,
        ]);

        return [$invoice->fresh(), $subscription];
    }

    private function actingAdmin(): self
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['admin:access']);

        return $this;
    }

    private function fakeGatewayForNewPayment(Invoice $invoice, string $gatewayPaymentId, ?string $merchantOrderId = null): void
    {
        Http::fake(function (HttpRequest $request) use ($gatewayPaymentId, $merchantOrderId): mixed {
            if ($request->method() === 'GET') {
                return Http::response(['data' => []]);
            }

            $attempt = PaymentAttempt::query()->latest('attempt_number')->firstOrFail();

            return Http::response([
                'data' => $this->gatewayPayment($attempt, $gatewayPaymentId, $merchantOrderId),
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function gatewayPayment(PaymentAttempt $attempt, string $gatewayPaymentId, ?string $merchantOrderId = null): array
    {
        return [
            'id' => $gatewayPaymentId,
            'merchant_order_id' => $merchantOrderId ?? $attempt->merchant_reference,
            'amount_minor' => 10000,
            'currency' => 'IDR',
            'status' => 'pending',
            'checkout_url' => 'https://checkout.test/session/001',
            'expires_at' => now()->addMinutes(30)->toISOString(),
        ];
    }
}
