<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\ApplicationPaymentGateway;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\PaymentAttempt;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\DanaFeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DanaInvoiceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.dana_gateway.environment' => 'sandbox']);
        config(['services.dana_gateway.base_urls.sandbox' => 'http://dana-gateway.test']);
        config(['services.dana_gateway.merchant_api_keys' => [
            'sandbox' => ['udev-central' => 'test-key'],
        ]]);
        config(['services.dana_gateway.merchants' => [
            'sandbox' => [
                'udev-central' => [
                    'name' => 'UDEV Central',
                    'is_active' => true,
                ],
            ],
        ]]);
        config(['services.udev_billing.subscription_dana_sandbox_merchant_code' => 'udev-central']);
    }

    public function test_fee_service_calculates_qris_and_dana_wallet_fees_correctly(): void
    {
        $service = app(DanaFeeService::class);

        // Tanpa tarif: customer fee Rp0, hak pemilik aplikasi tetap utuh Rp100.000
        $qrisFee = $service->calculateFee('udev-central', 'qris', 100_000);
        $this->assertSame(0.0, $qrisFee['customer_fee']);
        $this->assertSame(100_000.0, $qrisFee['total_amount']);
        $this->assertSame(0.0, $qrisFee['estimated_merchant_fee']);
        $this->assertSame(100_000.0, $qrisFee['estimated_net_settlement']);
        $this->assertSame('customer_surcharge', $qrisFee['customer_fee_policy']);

        // DANA Wallet tanpa tarif: customer fee Rp0, hak pemilik aplikasi tetap utuh Rp100.000
        $walletFee = $service->calculateFee('udev-central', 'dana_wallet', 100_000);
        $this->assertSame(0.0, $walletFee['customer_fee']);
        $this->assertSame(100_000.0, $walletFee['total_amount']);
        $this->assertSame(0.0, $walletFee['estimated_merchant_fee']);
        $this->assertSame(100_000.0, $walletFee['estimated_net_settlement']);
        $this->assertSame('customer_surcharge', $walletFee['customer_fee_policy']);
    }

    public function test_admin_can_preview_fee_breakdown_via_api(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['admin:access']);

        $response = $this->getJson('/api/v1/admin/payment-gateways/dana/fee?payment_method=qris&amount=200000');
        $response->assertOk()
            ->assertJsonPath('data.payment_method', 'qris')
            ->assertJsonPath('data.customer_fee', 0)
            ->assertJsonPath('data.total_amount', 200000)
            ->assertJsonPath('data.estimated_merchant_fee', 0)
            ->assertJsonPath('data.estimated_net_settlement', 200000);
    }

    public function test_checkout_dana_wallet_captures_fee_snapshot_and_returns_checkout_url(): void
    {
        $invoice = $this->createPendingInvoice(150_000);

        Http::fake([
            'http://dana-gateway.test/api/v1/payments*' => function (HttpRequest $request) use ($invoice) {
                if ($request->method() === 'GET') {
                    return Http::response(['data' => []]);
                }

                return Http::response([
                    'data' => [
                        'id' => '01JBK9Y8Z123456789ABCDEF01',
                        'merchant_order_id' => $invoice->invoice_number.'-A1',
                        'amount_minor' => 150000,
                        'currency' => 'IDR',
                        'status' => 'pending',
                        'checkout_url' => 'https://checkout.sandbox.dana.id/order/001',
                        'expires_at' => now()->addMinutes(30)->toISOString(),
                        'metadata' => [
                            'udev_sandbox_payment_instruction' => [
                                'method' => 'dana_wallet',
                                'label' => 'DANA Wallet',
                                'type' => 'redirect',
                                'message' => 'Lanjutkan pembayaran pada halaman DANA Wallet Sandbox.',
                            ],
                        ],
                    ],
                ]);
            },
        ]);

        Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['admin:access']);

        $response = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'dana_wallet',
            'provider' => 'dana',
        ], ['Idempotency-Key' => 'test-checkout-wallet-01']);

        $response->assertOk()
            ->assertJsonPath('data.payment_method', 'dana_wallet')
            ->assertJsonPath('data.checkout_status', 'ready')
            ->assertJsonPath('data.checkout_url', 'https://checkout.sandbox.dana.id/order/001')
            ->assertJsonPath('data.amount', '150000.00');

        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertNotNull($attempt->metadata['fee_breakdown']);
        $this->assertSame(0.0, (float) $attempt->metadata['fee_breakdown']['customer_fee']);
        $this->assertSame(0.0, (float) $attempt->metadata['fee_breakdown']['estimated_merchant_fee']);
        $this->assertSame(150000.0, (float) $attempt->metadata['fee_breakdown']['estimated_net_settlement']);
    }

    public function test_checkout_qris_dana_renders_mpm_and_captures_mdr_snapshot(): void
    {
        $invoice = $this->createPendingInvoice(50_000);

        Http::fake([
            'http://dana-gateway.test/api/v1/payments*' => function (HttpRequest $request) use ($invoice) {
                if ($request->method() === 'GET') {
                    return Http::response(['data' => []]);
                }

                return Http::response([
                    'data' => [
                        'id' => '01JBK9Y8Z123456789ABCDEF02',
                        'merchant_order_id' => $invoice->invoice_number.'-A1',
                        'amount_minor' => 50000,
                        'currency' => 'IDR',
                        'status' => 'pending',
                        'checkout_url' => null,
                        'expires_at' => now()->addMinutes(30)->toISOString(),
                        'metadata' => [
                            'udev_sandbox_payment_instruction' => [
                                'method' => 'qris',
                                'label' => 'QRIS',
                                'type' => 'qris_mpm',
                                'qr_content' => '00020101021226570011ID.DANA.WWW',
                                'payment_code' => '00020101021226570011ID.DANA.WWW',
                            ],
                        ],
                    ],
                ]);
            },
        ]);

        Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['admin:access']);

        $response = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'qris',
            'provider' => 'dana',
        ], ['Idempotency-Key' => 'test-checkout-qris-01']);

        $response->assertOk()
            ->assertJsonPath('data.payment_method', 'qris')
            ->assertJsonPath('data.checkout_status', 'ready')
            ->assertJsonPath('data.qr_content', '00020101021226570011ID.DANA.WWW')
            ->assertJsonPath('data.payment_instruction.type', 'qris_mpm');

        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(0.0, (float) $attempt->metadata['fee_breakdown']['customer_fee']);
        $this->assertSame(0.0, (float) $attempt->metadata['fee_breakdown']['estimated_merchant_fee']);
        $this->assertSame(50000.0, (float) $attempt->metadata['fee_breakdown']['estimated_net_settlement']);
    }

    public function test_admin_can_cancel_active_checkout_and_it_cancels_gateway_order(): void
    {
        $invoice = $this->createPendingInvoice(100_000);
        $attempt = PaymentAttempt::factory()->for($invoice)->create([
            'attempt_number' => 1,
            'provider' => 'dana',
            'gateway_merchant_code' => 'udev-central',
            'gateway_environment' => 'sandbox',
            'gateway_payment_id' => '01JBK9Y8Z123456789ABCDEF03',
            'status' => PaymentAttempt::StatusPending,
            'amount' => 100_000,
        ]);

        $gatewayCancelled = false;
        Http::fake([
            'http://dana-gateway.test/api/v1/payments/*/cancel*' => function () use (&$gatewayCancelled) {
                $gatewayCancelled = true;

                return Http::response([
                    'data' => ['status' => 'cancelled'],
                    'cancel_result' => ['response_code' => '2005700'],
                ]);
            },
        ]);

        Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['admin:access']);

        $response = $this->postJson("/api/v1/admin/payment-attempts/{$attempt->id}/cancel", [
            'reason' => 'Customer switched method',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertTrue($gatewayCancelled);
        $this->assertSame(PaymentAttempt::StatusCancelled, $attempt->fresh()->status);
        $this->assertSame(Invoice::StatusPending, $invoice->fresh()->status);
    }

    public function test_dana_webhook_settles_invoice_idempotently_without_double_charging(): void
    {
        $invoice = $this->createPendingInvoice(100_000);
        $attempt = PaymentAttempt::factory()->for($invoice)->create([
            'attempt_number' => 1,
            'provider' => 'dana',
            'merchant_reference' => $invoice->invoice_number.'-A1',
            'gateway_merchant_code' => 'udev-central',
            'gateway_environment' => 'sandbox',
            'gateway_payment_id' => '01JBK9Y8Z123456789ABCDEF04',
            'status' => PaymentAttempt::StatusPending,
            'amount' => 100_000,
        ]);

        $webhookPayload = [
            'merchant_order_id' => $invoice->invoice_number.'-A1',
            'originalReferenceNo' => '01JBK9Y8Z123456789ABCDEF04',
            'latestTransactionStatus' => '00',
            'status' => 'paid',
            'amount_minor' => 100000,
            'paid_at' => now()->toIso8601String(),
        ];

        Http::fake([
            'http://dana-gateway.test/api/v1/payments/*' => Http::response([
                'data' => [
                    'id' => '01JBK9Y8Z123456789ABCDEF04',
                    'merchant_order_id' => $invoice->invoice_number.'-A1',
                    'amount_minor' => 100000,
                    'currency' => 'IDR',
                    'status' => 'paid',
                    'paid_at' => now()->toISOString(),
                ],
            ]),
        ]);

        $headers = ['Authorization' => 'Bearer test-key'];

        // 1st webhook call
        $response1 = $this->postJson('/api/v1/payment-gateways/dana/webhook', $webhookPayload, $headers);
        $response1->assertOk()
            ->assertJsonPath('status', 'OK');

        $this->assertSame(Invoice::StatusPaid, $invoice->fresh()->status);
        $this->assertSame(PaymentAttempt::StatusSucceeded, $attempt->fresh()->status);
        $this->assertNotEmpty($attempt->fresh()->metadata['gateway_verification']['verified_at']);

        // 2nd webhook call (idempotent duplicate, zero double-settlement)
        $response2 = $this->postJson('/api/v1/payment-gateways/dana/webhook', $webhookPayload, $headers);
        $response2->assertOk()
            ->assertJsonPath('status', 'OK');

        $this->assertSame(1, PaymentAllocation::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_qr_image_endpoint_handles_dana_payment_attempt_gracefully_with_qr_content(): void
    {
        $invoice = $this->createPendingInvoice(75_000);
        $attempt = PaymentAttempt::factory()->for($invoice)->create([
            'attempt_number' => 1,
            'provider' => 'dana',
            'gateway_merchant_code' => 'udev-central',
            'gateway_environment' => 'sandbox',
            'status' => PaymentAttempt::StatusPending,
            'amount' => 75_000,
            'metadata' => [
                'payment_method' => 'qris',
                'qr_content' => '00020101021226570011ID.DANA.WWW01189360091500029570755405750005802ID',
                'payment_instruction' => [
                    'type' => 'qris_mpm',
                    'qr_content' => '00020101021226570011ID.DANA.WWW01189360091500029570755405750005802ID',
                ],
            ],
        ]);

        Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['admin:access']);

        $response = $this->getJson("/api/v1/admin/payment-attempts/{$attempt->id}/qr-image");

        $response->assertOk()
            ->assertJsonPath('provider', 'dana')
            ->assertJsonPath('payment_method', 'qris')
            ->assertJsonPath('qr_content', '00020101021226570011ID.DANA.WWW01189360091500029570755405750005802ID');
    }

    public function test_new_invoice_number_produces_attempt_merchant_reference_at_most_25_chars(): void
    {
        $app = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->for($app)->create();
        $plan = SubscriptionPlan::factory()->for($app)->create(['price' => 100_000]);

        $invoiceService = app(\App\Services\InvoiceService::class);
        $invoice = $invoiceService->createInvoice($customer->id, $plan->id, null, now()->addDays(3), 'idemp-inv-001');

        // Invoice number format is INV-YYYYMMDD-XXXXXXXX (21 characters)
        $this->assertSame(21, strlen($invoice->invoice_number));
        $this->assertMatchesRegularExpression('/\AINV-\d{8}-[A-Z0-9]{8}\z/', $invoice->invoice_number);

        // Attempt 1 reference is INV-YYYYMMDD-XXXXXXXX-A1 (24 characters, <= 25)
        $attempt1Ref = $invoice->invoice_number.'-A1';
        $this->assertSame(24, strlen($attempt1Ref));
        $this->assertLessThanOrEqual(25, strlen($attempt1Ref));

        // Attempt 10 reference is INV-YYYYMMDD-XXXXXXXX-A10 (25 characters, <= 25)
        $attempt10Ref = $invoice->invoice_number.'-A10';
        $this->assertSame(25, strlen($attempt10Ref));
        $this->assertLessThanOrEqual(25, strlen($attempt10Ref));
    }

    public function test_checkout_qris_stores_provider_reference_from_gateway(): void
    {
        $invoice = $this->createPendingInvoice(60_000);
        $qrisProviderRef = 'Q'.str_repeat('9', 24);

        Http::fake([
            'http://dana-gateway.test/api/v1/payments*' => function (HttpRequest $request) use ($invoice, $qrisProviderRef) {
                if ($request->method() === 'GET') {
                    return Http::response(['data' => []]);
                }

                return Http::response([
                    'data' => [
                        'id' => '01JBK9Y8Z123456789ABCDEF99',
                        'partner_reference_no' => $qrisProviderRef,
                        'merchant_order_id' => $invoice->invoice_number.'-A1',
                        'amount_minor' => 60000,
                        'currency' => 'IDR',
                        'status' => 'pending',
                        'checkout_url' => null,
                        'expires_at' => now()->addMinutes(30)->toISOString(),
                        'metadata' => [
                            'partner_reference_no' => $qrisProviderRef,
                            'udev_sandbox_payment_instruction' => [
                                'method' => 'qris',
                                'label' => 'QRIS',
                                'type' => 'qris_mpm',
                                'qr_content' => '00020101021226570011ID.DANA.WWW',
                                'payment_code' => '00020101021226570011ID.DANA.WWW',
                            ],
                        ],
                    ],
                ]);
            },
        ]);

        Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['admin:access']);

        $response = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'qris',
            'provider' => 'dana',
        ], ['Idempotency-Key' => 'test-checkout-qris-provider-ref']);

        $response->assertOk()
            ->assertJsonPath('data.payment_method', 'qris')
            ->assertJsonPath('data.checkout_status', 'ready');

        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame($qrisProviderRef, $attempt->provider_reference);
        $this->assertSame($qrisProviderRef, $attempt->metadata['partner_reference_no'] ?? null);
    }

    public function test_legacy_26_char_reference_can_be_retried_safely(): void
    {
        $invoice = $this->createPendingInvoice(75_000);
        // Force legacy 26-character reference: INV-YYYYMMDD-XXXXXXXXXX-A1
        $legacyRef = 'INV-20261115-CTDP60KWY7-A1';
        $invoice->update(['invoice_number' => 'INV-20261115-CTDP60KWY7']);

        $attempt = PaymentAttempt::factory()->for($invoice)->create([
            'attempt_number' => 1,
            'provider' => 'dana',
            'gateway_merchant_code' => 'udev-central',
            'gateway_environment' => 'sandbox',
            'gateway_payment_id' => '01JBK9Y8Z123456789ABCDEF88',
            'merchant_reference' => $legacyRef,
            'status' => PaymentAttempt::StatusInitiated,
            'amount' => 75_000,
            'currency' => 'IDR',
        ]);

        \App\Models\CheckoutOutboxMessage::query()->create([
            'payment_attempt_id' => $attempt->id,
            'idempotency_key' => 'dana-checkout-attempt-'.$attempt->id,
            'status' => \App\Models\CheckoutOutboxMessage::StatusPending,
            'attempts' => 1,
        ]);

        Http::fake([
            'http://dana-gateway.test/api/v1/payments*' => Http::response([
                'data' => [
                    'id' => '01JBK9Y8Z123456789ABCDEF88',
                    'merchant_order_id' => $legacyRef,
                    'amount_minor' => 75000,
                    'currency' => 'IDR',
                    'status' => 'pending',
                    'checkout_url' => 'https://checkout.sandbox.dana.id/order/legacy',
                    'expires_at' => now()->addMinutes(30)->toISOString(),
                    'metadata' => [
                        'udev_sandbox_payment_instruction' => [
                            'method' => 'dana_wallet',
                            'label' => 'DANA Wallet',
                            'type' => 'redirect',
                        ],
                    ],
                ],
            ]),
        ]);

        Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['admin:access']);

        $retryResponse = $this->postJson("/api/v1/admin/payment-attempts/{$attempt->id}/checkout/retry");
        $retryResponse->assertOk()
            ->assertJsonPath('data.status', PaymentAttempt::StatusPending);

        $this->assertSame($legacyRef, $attempt->fresh()->merchant_reference);
    }

    public function test_end_to_end_qris_production_flow_from_create_order_to_webhook_and_settlement(): void
    {
        $app = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->for($app)->create();
        $plan = SubscriptionPlan::factory()->for($app)->create(['price' => 150_000]);

        ApplicationPaymentGateway::query()->create([
            'application_id' => $app->id,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'udev-central',
            'is_active' => true,
        ]);

        $invoiceService = app(\App\Services\InvoiceService::class);
        $invoice = $invoiceService->createInvoice($customer->id, $plan->id, null, now()->addDays(3), 'idemp-e2e-inv-001');

        // 1. Verify invoice number format is 21 characters, attempt 1 reference is 24 characters (<= 25)
        $this->assertSame(21, strlen($invoice->invoice_number));
        $attempt1Ref = $invoice->invoice_number.'-A1';
        $this->assertSame(24, strlen($attempt1Ref));
        $this->assertLessThanOrEqual(25, strlen($attempt1Ref));

        // 2. Mock DANA gateway: Create Order returns pending status with 25-char partner_reference_no
        $qrisHashRef = 'Q'.substr(hash('sha256', 'e2e-gateway-payment-001'), 0, 24);
        $this->assertSame(25, strlen($qrisHashRef));

        $gatewayPaymentPayload = [
            'id' => '01JE2EGATEWAYPAYMENT00000000',
            'partner_reference_no' => $qrisHashRef,
            'merchant_order_id' => $attempt1Ref,
            'amount_minor' => 150000,
            'currency' => 'IDR',
            'status' => 'pending',
            'checkout_url' => null,
            'expires_at' => now()->addMinutes(30)->toISOString(),
            'metadata' => [
                'partner_reference_no' => $qrisHashRef,
                'payment_method' => 'qris',
                'udev_sandbox_payment_instruction' => [
                    'method' => 'qris',
                    'label' => 'QRIS',
                    'type' => 'qris_mpm',
                    'qr_content' => '00020101021226570011ID.DANA.WWW01189360091500029570755405750005802ID',
                    'payment_code' => '00020101021226570011ID.DANA.WWW01189360091500029570755405750005802ID',
                ],
            ],
        ];

        $isGatewayPaid = false;
        Http::fake([
            'http://dana-gateway.test/api/v1/payments*' => function (HttpRequest $request) use (&$gatewayPaymentPayload, &$isGatewayPaid) {
                $payload = $gatewayPaymentPayload;
                if ($isGatewayPaid) {
                    $payload['status'] = 'paid';
                    $payload['paid_at'] = now()->toISOString();
                }

                return Http::response(['data' => $payload]);
            },
        ]);

        Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['admin:access']);

        // 3. Request Checkout (POST /checkout)
        $checkoutResponse = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'qris',
            'provider' => 'dana',
        ], ['Idempotency-Key' => 'idemp-checkout-e2e-001']);

        $checkoutResponse->assertOk()
            ->assertJsonPath('data.payment_method', 'qris')
            ->assertJsonPath('data.checkout_status', 'ready')
            ->assertJsonPath('data.qr_content', '00020101021226570011ID.DANA.WWW01189360091500029570755405750005802ID');

        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(PaymentAttempt::StatusPending, $attempt->status);
        $this->assertSame($attempt1Ref, $attempt->merchant_reference);
        $this->assertSame($qrisHashRef, $attempt->provider_reference);
        $this->assertSame('01JE2EGATEWAYPAYMENT00000000', $attempt->gateway_payment_id);

        // 4. Retry with identical Idempotency-Key must not duplicate attempt
        $retryCheckoutResponse = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'qris',
            'provider' => 'dana',
        ], ['Idempotency-Key' => 'idemp-checkout-e2e-001']);

        $retryCheckoutResponse->assertOk();
        $this->assertSame(1, PaymentAttempt::query()->where('invoice_id', $invoice->id)->count());

        // 5. Simulate DANA sending Finish Notify Webhook with originalPartnerReferenceNo
        $webhookPayload = [
            'merchant_order_id' => $attempt1Ref,
            'partnerReferenceNo' => $qrisHashRef,
            'originalPartnerReferenceNo' => $qrisHashRef,
            'id' => '01JE2EGATEWAYPAYMENT00000000',
            'status' => 'paid',
            'latestTransactionStatus' => '00',
            'amount_minor' => 150000,
            'currency' => 'IDR',
            'paid_at' => now()->toIso8601String(),
        ];

        // Mark gateway mock as paid for status verification during webhook processing
        $isGatewayPaid = true;

        $headers = ['Authorization' => 'Bearer test-key'];

        $webhookResponse = $this->postJson('/api/v1/payment-gateways/dana/webhook', $webhookPayload, $headers);
        $webhookResponse->assertOk()
            ->assertJsonPath('status', 'OK')
            ->assertJsonPath('merchant_reference', $attempt1Ref);

        // 6. Verify UDEV Settlement
        $freshAttempt = $attempt->fresh();
        $this->assertSame(PaymentAttempt::StatusSucceeded, $freshAttempt->status);
        $this->assertSame(Invoice::StatusPaid, $invoice->fresh()->status);
        $this->assertSame(1, PaymentAllocation::query()->where('invoice_id', $invoice->id)->count());
        $this->assertNotEmpty($freshAttempt->metadata['gateway_verification']['verified_at']);

        // 7. Duplicate Webhook delivery is idempotent and does not double-settle
        $duplicateWebhookResponse = $this->postJson('/api/v1/payment-gateways/dana/webhook', $webhookPayload, $headers);
        $duplicateWebhookResponse->assertOk();
        $this->assertSame(1, PaymentAllocation::query()->where('invoice_id', $invoice->id)->count());
    }

    private function createPendingInvoice(float $amount = 100_000): Invoice
    {
        $app = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->for($app)->create();
        $plan = SubscriptionPlan::factory()->for($app)->create(['price' => $amount]);

        ApplicationPaymentGateway::query()->create([
            'application_id' => $app->id,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'udev-central',
            'is_active' => true,
        ]);

        return Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => $amount,
            'currency' => 'IDR',
            'status' => Invoice::StatusPending,
            'due_at' => now()->addDays(3),
            'metadata' => [
                'normal_price' => $amount,
                'referral_discount' => 0.0,
            ],
        ]);
    }
}
