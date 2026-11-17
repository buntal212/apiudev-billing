<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\ApplicationPaymentGateway;
use App\Models\DanaEnvironmentSetting;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\PaymentAttempt;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\DanaFeeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class ProductionReadinessE2EVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Application $application;
    private string $token;
    private ApplicationCustomer $customer;
    private SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-17 10:00:00');

        config(['services.dana_gateway.environment' => 'sandbox']);
        config(['services.dana_gateway.base_urls.sandbox' => 'http://dana-gateway.test']);
        config(['services.dana_gateway.merchant_api_keys' => [
            'sandbox' => ['larissama-dana' => 'test-dana-api-key'],
        ]]);
        config(['services.dana_gateway.merchants' => [
            'sandbox' => [
                'larissama-dana' => [
                    'name' => 'Larissama DANA Merchant',
                    'is_active' => true,
                ],
            ],
        ]]);

        DanaEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'dana'],
            [
                'active_environment' => 'sandbox',
                'primary_merchant_code' => 'larissama-dana',
                'is_production_ready' => false,
            ]
        );

        [$this->application, $this->token] = $this->createApplicationWithToken('LarissamaApp');

        $this->customer = ApplicationCustomer::factory()->create([
            'application_id' => $this->application->id,
            'external_customer_id' => 'WARUNG-E2E-001',
            'name' => 'Warung Berkah E2E',
        ]);

        $this->plan = SubscriptionPlan::factory()->create([
            'application_id' => $this->application->id,
            'code' => 'WARUNG-PRO-30D',
            'name' => 'Paket Warung Pro',
            'price' => '250000.00',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        ApplicationPaymentGateway::query()->create([
            'application_id' => $this->application->id,
            'gateway_merchant_code' => 'larissama-dana',
            'provider' => 'dana',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);
    }

    /**
     * Item 4: Simulasi E2E Mock: Create Invoice -> Checkout DANA QRIS -> Webhook Valid -> Settlement -> Subscription Aktif.
     */
    public function test_e2e_full_flow_create_invoice_checkout_dana_qris_webhook_settlement_subscription_active(): void
    {
        // 1. Create Invoice via External Integration API
        $createRes = $this->withToken($this->token)
            ->postJson("/api/v1/integrations/customers/{$this->customer->external_customer_id}/invoices", [
                'subscription_plan_code' => $this->plan->code,
                'due_at' => now()->addDays(2)->toISOString(),
                'period_count' => 1,
            ], ['Idempotency-Key' => 'e2e-inv-key-001'])
            ->assertCreated();

        $invoiceNumber = $createRes->json('data.invoice_number');
        $this->assertNotEmpty($invoiceNumber);
        $this->assertSame('pending', $createRes->json('data.status'));
        $this->assertSame('250000.00', $createRes->json('data.pricing.final_price'));

        // 2. Setup Mock for Checkout & Webhook Gateway Verification
        Http::fake(function (HttpRequest $request) use ($invoiceNumber) {
            $url = $request->url();
            if (str_contains($url, '/status')) {
                return Http::response([
                    'data' => [
                        'id' => '01JBK9Y8Z123456789DANAQRIS',
                        'merchant_order_id' => $invoiceNumber.'-A1',
                        'amount_minor' => 250000,
                        'currency' => 'IDR',
                        'status' => 'paid',
                        'paid_at' => now()->toISOString(),
                    ],
                ]);
            }

            if ($request->method() === 'GET') {
                return Http::response(['data' => []]);
            }

            return Http::response([
                'data' => [
                    'id' => '01JBK9Y8Z123456789DANAQRIS',
                    'merchant_order_id' => $invoiceNumber.'-A1',
                    'amount_minor' => 250000,
                    'currency' => 'IDR',
                    'status' => 'pending',
                    'checkout_url' => null,
                    'expires_at' => now()->addMinutes(30)->toISOString(),
                    'metadata' => [
                        'udev_sandbox_payment_instruction' => [
                            'method' => 'qris',
                            'label' => 'QRIS',
                            'type' => 'qris_mpm',
                            'qr_content' => '00020101021226670016ID.DANA.E2E.QRIS',
                            'payment_code' => '00020101021226670016ID.DANA.E2E.QRIS',
                        ],
                    ],
                ],
            ]);
        });

        $checkoutRes = $this->withToken($this->token)
            ->postJson("/api/v1/integrations/invoices/{$invoiceNumber}/checkout", [
                'provider' => 'dana',
                'payment_method' => 'qris',
                'idempotency_key' => 'e2e-chk-key-001',
            ])
            ->assertOk();

        $checkoutRes->assertJsonPath('data.payment.provider', 'dana')
            ->assertJsonPath('data.payment.payment_method', 'qris')
            ->assertJsonPath('data.payment.amount', '250000.00')
            ->assertJsonPath('data.payment.admin_fee', 0)
            ->assertJsonPath('data.payment.checkout_status', 'ready')
            ->assertJsonPath('data.payment.qr_content', '00020101021226670016ID.DANA.E2E.QRIS')
            ->assertJsonPath('data.payment.gateway_environment', 'sandbox');

        $attempt = PaymentAttempt::query()->where('merchant_reference', $invoiceNumber.'-A1')->sole();
        $this->assertSame(PaymentAttempt::StatusPending, $attempt->status);
        $this->assertSame('sandbox', $attempt->gateway_environment);
        $this->assertSame('larissama-dana', $attempt->gateway_merchant_code);

        // 3. Webhook DANA Valid Arrival
        $webhookPayload = [
            'merchant_order_id' => $invoiceNumber.'-A1',
            'originalReferenceNo' => '01JBK9Y8Z123456789DANAQRIS',
            'latestTransactionStatus' => '00',
            'status' => 'paid',
            'amount_minor' => 250000,
            'paid_at' => now()->toIso8601String(),
        ];

        $webhookRes = $this->postJson(
            '/api/v1/webhooks/dana',
            $webhookPayload,
            ['Authorization' => 'Bearer test-dana-api-key']
        );
        $webhookRes->assertOk();

        // 4. Verification of Settlement and Subscription Activation
        $invoice = Invoice::query()->where('invoice_number', $invoiceNumber)->sole();
        $this->assertSame(Invoice::StatusPaid, $invoice->status);
        $this->assertNotNull($invoice->paid_at);

        $attempt->refresh();
        $this->assertSame(PaymentAttempt::StatusSucceeded, $attempt->status);

        // Exactly 1 allocation created
        $allocation = PaymentAllocation::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame('250000.00', $allocation->amount);

        // Subscription automatically active
        $subscription = Subscription::query()->where('application_customer_id', $this->customer->id)->latest('id')->first();
        $this->assertNotNull($subscription);
        $this->assertSame(Subscription::StatusActive, $subscription->status);
        $this->assertTrue($subscription->expires_at->isFuture());

        // 5. Invoice detail API confirms paid and active subscription
        $detailRes = $this->withToken($this->token)
            ->getJson("/api/v1/integrations/invoices/{$invoiceNumber}")
            ->assertOk();

        $detailRes->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment.status', 'succeeded')
            ->assertJsonPath('data.payment.provider', 'dana');
    }

    /**
     * Item 5: Retry, Expired, Idempotensi, dan Isolasi Antar Aplikasi.
     */
    public function test_retry_and_idempotency_protection(): void
    {
        $invoice = Invoice::factory()->create([
            'application_customer_id' => $this->customer->id,
            'subscription_plan_id' => $this->plan->id,
            'amount' => '100000.00',
            'currency' => 'IDR',
            'status' => Invoice::StatusPending,
        ]);

        Http::fake(function (HttpRequest $request) use ($invoice) {
            $url = $request->url();
            if (str_contains($url, '/status')) {
                return Http::response([
                    'data' => [
                        'id' => 'DANA-IDEMP-01',
                        'merchant_order_id' => $invoice->invoice_number.'-A1',
                        'amount_minor' => 100000,
                        'currency' => 'IDR',
                        'status' => 'paid',
                        'paid_at' => now()->toISOString(),
                    ],
                ]);
            }

            if ($request->method() === 'GET') {
                return Http::response(['data' => []]);
            }

            return Http::response([
                'data' => [
                    'id' => 'DANA-IDEMP-01',
                    'merchant_order_id' => $invoice->invoice_number.'-A1',
                    'amount_minor' => 100000,
                    'currency' => 'IDR',
                    'status' => 'pending',
                    'checkout_url' => null,
                    'expires_at' => now()->addMinutes(30)->toISOString(),
                    'metadata' => [
                        'udev_sandbox_payment_instruction' => [
                            'method' => 'qris',
                            'label' => 'QRIS',
                            'type' => 'qris_mpm',
                            'qr_content' => 'QRIS-IDEMPOTENCY',
                            'payment_code' => 'QRIS-IDEMPOTENCY',
                        ],
                    ],
                ],
            ]);
        });

        // First checkout call
        $res1 = $this->withToken($this->token)->postJson("/api/v1/integrations/invoices/{$invoice->invoice_number}/checkout", [
            'provider' => 'dana',
            'payment_method' => 'qris',
            'idempotency_key' => 'idemp-key-repeat-01',
        ]);
        $this->assertTrue(in_array($res1->status(), [200, 202], true));

        // Second checkout call with EXACT same idempotency key (must return same attempt, no duplicate)
        $res2 = $this->withToken($this->token)->postJson("/api/v1/integrations/invoices/{$invoice->invoice_number}/checkout", [
            'provider' => 'dana',
            'payment_method' => 'qris',
            'idempotency_key' => 'idemp-key-repeat-01',
        ]);
        $this->assertSame($res1->status(), $res2->status());

        $this->assertSame(1, PaymentAttempt::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame($res1->json('data.payment.transaction_id'), $res2->json('data.payment.transaction_id'));

        // Idempotent webhook handling (duplicate webhook must not double settle)
        $webhookPayload = [
            'merchant_order_id' => $invoice->invoice_number.'-A1',
            'originalReferenceNo' => 'DANA-IDEMP-01',
            'latestTransactionStatus' => '00',
            'status' => 'paid',
            'amount_minor' => 100000,
            'paid_at' => now()->toIso8601String(),
        ];

        $this->postJson('/api/v1/webhooks/dana', $webhookPayload, ['Authorization' => 'Bearer test-dana-api-key'])->assertOk();
        $this->assertSame(1, PaymentAllocation::query()->where('invoice_id', $invoice->id)->count());

        // Resend identical webhook
        $this->postJson('/api/v1/webhooks/dana', $webhookPayload, ['Authorization' => 'Bearer test-dana-api-key'])->assertOk();
        $this->assertSame(1, PaymentAllocation::query()->where('invoice_id', $invoice->id)->count());
    }

    /**
     * Item 5: Expired Payment Attempt Handling & Fresh Attempt Creation.
     */
    public function test_expired_payment_attempt_allows_fresh_checkout_attempt(): void
    {
        $invoice = Invoice::factory()->create([
            'application_customer_id' => $this->customer->id,
            'subscription_plan_id' => $this->plan->id,
            'amount' => '100000.00',
            'currency' => 'IDR',
            'status' => Invoice::StatusPending,
        ]);

        // Expired attempt 1
        $attempt1 = PaymentAttempt::factory()->for($invoice)->create([
            'attempt_number' => 1,
            'provider' => 'dana',
            'gateway_merchant_code' => 'larissama-dana',
            'gateway_environment' => 'sandbox',
            'status' => PaymentAttempt::StatusExpired,
            'checkout_expires_at' => now()->subHour(),
        ]);

        Http::fake(function (HttpRequest $request) use ($invoice) {
            return Http::response([
                'data' => [
                    'id' => 'DANA-FRESH-02',
                    'merchant_order_id' => $invoice->invoice_number.'-A2',
                    'amount_minor' => 100000,
                    'currency' => 'IDR',
                    'status' => 'pending',
                    'checkout_url' => null,
                    'expires_at' => now()->addMinutes(30)->toISOString(),
                    'metadata' => [
                        'udev_sandbox_payment_instruction' => [
                            'method' => 'qris',
                            'label' => 'QRIS',
                            'type' => 'qris_mpm',
                            'qr_content' => 'QRIS-FRESH-02',
                            'payment_code' => 'QRIS-FRESH-02',
                        ],
                    ],
                ],
            ]);
        });

        // Fresh checkout attempt with new idempotency key
        $res = $this->withToken($this->token)->postJson("/api/v1/integrations/invoices/{$invoice->invoice_number}/checkout", [
            'provider' => 'dana',
            'payment_method' => 'qris',
            'idempotency_key' => 'fresh-chk-key-02',
        ])->assertOk();

        $this->assertSame(2, PaymentAttempt::query()->where('invoice_id', $invoice->id)->count());
        $attempt2 = PaymentAttempt::query()->where('invoice_id', $invoice->id)->where('attempt_number', 2)->sole();
        $this->assertSame(PaymentAttempt::StatusPending, $attempt2->status);
        $this->assertSame($invoice->invoice_number.'-A2', $attempt2->merchant_reference);
    }

    /**
     * Item 5: Isolasi Ketat Antar Aplikasi.
     */
    public function test_strict_cross_application_isolation(): void
    {
        [$otherApp, $otherToken] = $this->createApplicationWithToken('OtherApp');

        // Invoice belongs to $this->application
        $invoice = Invoice::factory()->create([
            'application_customer_id' => $this->customer->id,
            'subscription_plan_id' => $this->plan->id,
            'amount' => '100000.00',
            'status' => Invoice::StatusPending,
        ]);

        // OtherApp attempts to checkout this invoice -> MUST be 404
        $this->withToken($otherToken)->postJson("/api/v1/integrations/invoices/{$invoice->invoice_number}/checkout", [
            'provider' => 'dana',
            'payment_method' => 'qris',
            'idempotency_key' => 'hack-cross-tenant-01',
        ])->assertStatus(Response::HTTP_NOT_FOUND);

        // OtherApp attempts to fetch invoice detail -> MUST be 404
        $this->withToken($otherToken)->getJson("/api/v1/integrations/invoices/{$invoice->invoice_number}")
            ->assertStatus(Response::HTTP_NOT_FOUND);

        // OtherApp attempts to view customer transactions -> MUST be 404
        $this->withToken($otherToken)->getJson("/api/v1/integrations/customers/{$this->customer->external_customer_id}/transactions")
            ->assertStatus(Response::HTTP_NOT_FOUND);
    }

    /**
     * Item 6: Pastikan Environment Production/Sandbox Tidak Tertukar.
     */
    public function test_environment_production_and_sandbox_separation(): void
    {
        $invoice = Invoice::factory()->create([
            'application_customer_id' => $this->customer->id,
            'subscription_plan_id' => $this->plan->id,
            'amount' => '100000.00',
            'status' => Invoice::StatusPending,
        ]);

        // Sandbox setup
        $sandboxAttempt = PaymentAttempt::factory()->for($invoice)->create([
            'attempt_number' => 1,
            'provider' => 'dana',
            'gateway_merchant_code' => 'larissama-dana',
            'gateway_environment' => 'sandbox',
            'status' => PaymentAttempt::StatusPending,
        ]);

        $this->assertSame('sandbox', $sandboxAttempt->gateway_environment);

        // Production setup
        $prodAttempt = PaymentAttempt::factory()->for($invoice)->create([
            'attempt_number' => 2,
            'provider' => 'dana',
            'gateway_merchant_code' => 'larissama-dana',
            'gateway_environment' => 'production',
            'status' => PaymentAttempt::StatusPending,
        ]);

        $this->assertSame('production', $prodAttempt->gateway_environment);

        // Detail endpoint exposes the latest attempt's environment accurately
        $detailRes = $this->withToken($this->token)->getJson("/api/v1/integrations/invoices/{$invoice->invoice_number}")->assertOk();
        $detailRes->assertJsonPath('data.payment.gateway_environment', 'production');
    }

    /**
     * Item 7: Verifikasi Kebijakan Zero Surcharge DANA Tetap Aktif.
     */
    public function test_dana_zero_surcharge_policy_is_active(): void
    {
        $danaFeeService = app(DanaFeeService::class);

        // Zero surcharge on QRIS
        $qrisResult = $danaFeeService->calculateFee('larissama-dana', 'qris', 350_000);
        $this->assertSame(0.0, $qrisResult['customer_fee'], 'Customer surcharge for QRIS must be 0.0');
        $this->assertSame(350_000.0, $qrisResult['total_amount'], 'Total payable must equal base amount');

        // Zero surcharge on DANA Wallet
        $walletResult = $danaFeeService->calculateFee('larissama-dana', 'dana_wallet', 500_000);
        $this->assertSame(0.0, $walletResult['customer_fee'], 'Customer surcharge for DANA Wallet must be 0.0');
        $this->assertSame(500_000.0, $walletResult['total_amount'], 'Total payable must equal base amount');
    }

    /**
     * @return array{0: Application, 1: string}
     */
    private function createApplicationWithToken(string $appName): array
    {
        $application = Application::factory()->create(['name' => $appName]);
        $plainToken = 'tok-'.strtolower($appName).'-'.bin2hex(random_bytes(8));

        $application->integrationTokens()->create([
            'name' => "Global Token {$appName}",
            'token_hash' => hash('sha256', $plainToken),
            'token_prefix' => substr($plainToken, 0, 8),
            'token_kind' => 'global',
            'status' => 'active',
            'scopes' => ['*'],
        ]);

        return [$application, $plainToken];
    }
}
