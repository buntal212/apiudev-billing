<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\ApplicationPaymentGateway;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\DanaFeeService;
use Carbon\CarbonImmutable;
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

        // QRIS: MDR 0.7%, absorbed by merchant, customer fee Rp0
        $qrisFee = $service->calculateFee('udev-central', 'qris', 100_000);
        $this->assertSame(0.0, $qrisFee['customer_fee']);
        $this->assertSame(100_000.0, $qrisFee['total_amount']);
        $this->assertSame(700.0, $qrisFee['estimated_merchant_fee']);
        $this->assertSame(99_300.0, $qrisFee['estimated_net_settlement']);
        $this->assertSame('absorbed_by_merchant', $qrisFee['customer_fee_policy']);

        // DANA Wallet: 1.5%, customer fee default Rp0
        $walletFee = $service->calculateFee('udev-central', 'dana_wallet', 100_000);
        $this->assertSame(0.0, $walletFee['customer_fee']);
        $this->assertSame(100_000.0, $walletFee['total_amount']);
        $this->assertSame(1500.0, $walletFee['estimated_merchant_fee']);
        $this->assertSame(98_500.0, $walletFee['estimated_net_settlement']);
        $this->assertSame('zero_surcharge_policy', $walletFee['customer_fee_policy']);
    }

    public function test_admin_can_preview_fee_breakdown_via_api(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['admin:access']);

        $response = $this->getJson('/api/v1/admin/payment-gateways/dana/fee?payment_method=qris&amount=200000');
        $response->assertOk()
            ->assertJsonPath('data.payment_method', 'qris')
            ->assertJsonPath('data.customer_fee', 0)
            ->assertJsonPath('data.total_amount', 200000)
            ->assertJsonPath('data.estimated_merchant_fee', 1400)
            ->assertJsonPath('data.estimated_net_settlement', 198600);
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
        $this->assertSame(2250.0, (float) $attempt->metadata['fee_breakdown']['estimated_merchant_fee']);
        $this->assertSame(147750.0, (float) $attempt->metadata['fee_breakdown']['estimated_net_settlement']);
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
        $this->assertSame(350.0, (float) $attempt->metadata['fee_breakdown']['estimated_merchant_fee']);
        $this->assertSame(49650.0, (float) $attempt->metadata['fee_breakdown']['estimated_net_settlement']);
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

        // 1st webhook call
        $response1 = $this->postJson('/api/v1/payment-gateways/dana/webhook', $webhookPayload);
        $response1->assertOk()
            ->assertJsonPath('status', 'OK');

        $this->assertSame(Invoice::StatusPaid, $invoice->fresh()->status);
        $this->assertSame(PaymentAttempt::StatusSucceeded, $attempt->fresh()->status);

        // 2nd webhook call (idempotent duplicate, zero double-settlement)
        $response2 = $this->postJson('/api/v1/payment-gateways/dana/webhook', $webhookPayload);
        $response2->assertOk()
            ->assertJsonPath('status', 'OK');

        $this->assertSame(1, \App\Models\PaymentAllocation::query()->where('invoice_id', $invoice->id)->count());
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
