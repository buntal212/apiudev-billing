<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\ApplicationPaymentGateway;
use App\Models\Invoice;
use App\Models\MidtransMerchantCredential;
use App\Models\PaymentAttempt;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\MidtransSecretStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentResultApiTest extends TestCase
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

    public function test_public_result_uses_gateway_status_and_never_trusts_redirect_status(): void
    {
        $paymentAttempt = $this->paymentAttempt();
        Http::fake([
            "https://gateway.test/api/v1/payments/{$paymentAttempt->gateway_payment_id}/status" => Http::response([
                'data' => $this->gatewayPayment($paymentAttempt, 'paid'),
            ]),
        ]);

        $this->getJson("/api/v1/payment-results/{$paymentAttempt->gateway_payment_id}?status=failed")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.reference', $paymentAttempt->gateway_payment_id)
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.amount', '10000.00')
            ->assertJsonMissingPath('data.invoice_number')
            ->assertJsonMissingPath('data.customer');

        $this->assertDatabaseHas('payment_attempts', [
            'id' => $paymentAttempt->id,
            'status' => PaymentAttempt::StatusSucceeded,
        ]);
        Http::assertSentCount(1);
    }

    public function test_public_result_returns_pending_then_expired_status_from_gateway(): void
    {
        $paymentAttempt = $this->paymentAttempt();
        Http::fake([
            "https://gateway.test/api/v1/payments/{$paymentAttempt->gateway_payment_id}/status" => Http::sequence()
                ->push(['data' => $this->gatewayPayment($paymentAttempt, 'pending')])
                ->push(['data' => $this->gatewayPayment($paymentAttempt, 'expired')]),
        ]);

        $this->getJson("/api/v1/payment-results/{$paymentAttempt->gateway_payment_id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $this->getJson("/api/v1/payment-results/{$paymentAttempt->gateway_payment_id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'expired');

        $this->assertDatabaseHas('payment_attempts', [
            'id' => $paymentAttempt->id,
            'status' => PaymentAttempt::StatusExpired,
        ]);
    }

    public function test_verified_paid_gateway_result_settles_invoice_and_renews_subscription(): void
    {
        $paymentAttempt = $this->paymentAttempt();
        $invoice = $paymentAttempt->invoice;
        $invoice->update(['amount' => '10000.00']);
        $subscription = Subscription::factory()->create([
            'application_customer_id' => $invoice->application_customer_id,
            'subscription_plan_id' => $invoice->subscription_plan_id,
            'expires_at' => now()->addDays(30),
        ]);
        $invoice->update(['subscription_id' => $subscription->id]);
        ApplicationPaymentGateway::query()->create([
            'application_id' => $invoice->plan->application_id,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'udev-demo',
            'is_active' => true,
        ]);
        $previousExpiry = $subscription->fresh()->expires_at->toImmutable();

        Http::fake([
            "https://gateway.test/api/v1/payments/{$paymentAttempt->gateway_payment_id}/status" => Http::response([
                'data' => $this->gatewayPayment($paymentAttempt, 'paid'),
            ]),
        ]);

        $this->getJson("/api/v1/payment-results/{$paymentAttempt->gateway_payment_id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, $invoice->fresh()->paymentAllocation()->count());
        $this->assertTrue($subscription->fresh()->expires_at->equalTo($previousExpiry->addDays(30)));
        $this->assertDatabaseCount('subscription_period_events', 1);
    }

    public function test_unknown_or_malformed_reference_does_not_disclose_payment_data(): void
    {
        $this->getJson('/api/v1/payment-results/'.Str::ulid())
            ->assertNotFound()
            ->assertJsonPath('message', 'Data yang Anda cari tidak ditemukan.');

        $this->getJson('/api/v1/payment-results/not-a-reference')
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_return_url_only_uses_registered_https_application_url(): void
    {
        $paymentAttempt = $this->paymentAttempt();
        $paymentAttempt->update([
            'status' => PaymentAttempt::StatusSucceeded,
            'completed_at' => now(),
        ]);
        $application = $paymentAttempt->invoice->plan->application;
        $application->update(['application_url' => 'https://larissama.example.test/billing']);

        $this->getJson("/api/v1/payment-results/{$paymentAttempt->gateway_payment_id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.return_url', 'https://larissama.example.test/billing');

        $application->update(['application_url' => 'http://untrusted.example.test']);

        $this->getJson("/api/v1/payment-results/{$paymentAttempt->gateway_payment_id}")
            ->assertOk()
            ->assertJsonPath('data.return_url', null);

        Http::assertNothingSent();
    }

    public function test_payment_results_endpoint_handles_midtrans_uuid_reference_and_syncs_expired_status_idempotently(): void
    {
        $app = Application::factory()->create(['name' => 'Aplikasi Larissama']);
        $customer = ApplicationCustomer::factory()->create(['application_id' => $app->id]);
        $plan = SubscriptionPlan::factory()->create([
            'application_id' => $app->id,
            'price' => '10000.00',
            'duration_days' => 30,
        ]);
        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '10000.00',
            'currency' => 'IDR',
            'duration_days' => 30,
            'status' => Invoice::StatusPending,
            'due_at' => now()->addDays(3),
        ]);

        $secretStore = app(MidtransSecretStore::class);
        $encrypted = $secretStore->encryptSecret('SB-Mid-server-TESTKEY1234567890', 'larissama', 'server_key');

        MidtransMerchantCredential::create([
            'merchant_code' => 'larissama',
            'merchant_name' => 'Larissama Store',
            'environment' => 'sandbox',
            'encrypted_server_key' => $encrypted['ciphertext'],
            'server_key_nonce' => $encrypted['nonce'],
            'server_key_tag' => $encrypted['tag'],
            'key_version' => $encrypted['key_version'],
            'is_active' => true,
        ]);

        ApplicationPaymentGateway::create([
            'application_id' => $app->id,
            'provider' => 'midtrans',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'larissama',
            'is_active' => true,
        ]);

        // Case A5: payment attempt 29 with UUID gateway_payment_id
        $uuid = '10dcd836-21f0-4919-af47-c781bac0c118';
        $orderId = 'INV-20261101-VDWNVAOGGV-A5';
        $attempt = PaymentAttempt::create([
            'id' => 29,
            'invoice_id' => $invoice->id,
            'attempt_number' => 5,
            'provider' => 'midtrans',
            'merchant_reference' => $orderId,
            'gateway_merchant_code' => 'larissama',
            'gateway_environment' => 'sandbox',
            'amount' => '10000.00',
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusPending,
            'gateway_payment_id' => $uuid,
            'idempotency_key' => 'checkout:'.$invoice->id.':5',
            'checkout_expires_at' => '2026-11-03 14:47:43',
            'requested_at' => now(),
            'metadata' => [
                'order_id' => $orderId,
                'transaction_id' => $uuid,
                'payment_method' => 'qris',
            ],
        ]);

        config()->set('services.midtrans_gateway', [
            'sandbox_base_url' => 'https://api.sandbox.midtrans.com/v2',
            'gateway_service_url' => '',
            'service_token' => 'test-service-token-123456',
            'timeout' => 5,
            'connect_timeout' => 3,
        ]);

        // Midtrans returns status=expire, expiry_time=2026-11-02 22:02:43 (WIB)
        Http::fake([
            "https://api.sandbox.midtrans.com/v2/{$orderId}/status" => Http::response([
                'status_code' => '200',
                'status_message' => 'Success, transaction is found',
                'transaction_id' => $uuid,
                'order_id' => $orderId,
                'gross_amount' => '10000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'expire',
                'expiry_time' => '2026-11-02 22:02:43',
            ], 200),
        ]);

        $response = $this->getJson("/api/v1/payment-results/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.status', 'expired')
            ->assertJsonPath('data.reference', $uuid);

        $attempt->refresh();
        $this->assertSame(PaymentAttempt::StatusExpired, $attempt->status);
        $this->assertSame('2026-11-02 15:02:43', $attempt->checkout_expires_at->format('Y-m-d H:i:s'));

        $this->assertSame(Invoice::StatusPending, $invoice->fresh()->status);
        $this->assertSame(0, $invoice->fresh()->paymentAllocation()->count());

        // Idempotent second call
        $this->getJson("/api/v1/payment-results/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.status', 'expired')
            ->assertJsonPath('data.reference', $uuid);
    }

    public function test_payment_results_falls_back_to_direct_midtrans_when_microservice_returns_404_merchant_not_found(): void
    {
        $app = Application::factory()->create(['name' => 'Aplikasi Larissama']);
        $customer = ApplicationCustomer::factory()->create(['application_id' => $app->id]);
        $plan = SubscriptionPlan::factory()->create([
            'application_id' => $app->id,
            'price' => '10000.00',
            'duration_days' => 30,
        ]);
        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '10000.00',
            'currency' => 'IDR',
            'duration_days' => 30,
            'status' => Invoice::StatusPending,
            'due_at' => now()->addDays(3),
        ]);

        $secretStore = app(MidtransSecretStore::class);
        $encrypted = $secretStore->encryptSecret('SB-Mid-server-TESTKEY1234567890', 'larissama', 'server_key');

        MidtransMerchantCredential::create([
            'merchant_code' => 'larissama',
            'merchant_name' => 'Larissama Store',
            'environment' => 'sandbox',
            'encrypted_server_key' => $encrypted['ciphertext'],
            'server_key_nonce' => $encrypted['nonce'],
            'server_key_tag' => $encrypted['tag'],
            'key_version' => $encrypted['key_version'],
            'is_active' => true,
        ]);

        ApplicationPaymentGateway::create([
            'application_id' => $app->id,
            'provider' => 'midtrans',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'larissama',
            'is_active' => true,
        ]);

        $uuid = '10dcd836-21f0-4919-af47-c781bac0c118';
        $orderId = 'INV-20261101-VDWNVAOGGV-A5';
        $attempt = PaymentAttempt::create([
            'id' => 29,
            'invoice_id' => $invoice->id,
            'attempt_number' => 5,
            'provider' => 'midtrans',
            'merchant_reference' => $orderId,
            'gateway_merchant_code' => 'larissama',
            'gateway_environment' => 'sandbox',
            'amount' => '10000.00',
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusPending,
            'gateway_payment_id' => $uuid,
            'idempotency_key' => 'checkout:'.$invoice->id.':5',
            'requested_at' => now(),
        ]);

        config()->set('services.midtrans_gateway', [
            'sandbox_base_url' => 'https://api.sandbox.midtrans.com/v2',
            'gateway_service_url' => 'https://midtrans-service.test',
            'service_token' => 'test-service-token-123456',
            'timeout' => 5,
            'connect_timeout' => 3,
        ]);

        Http::fake([
            // Microservice returns 404 merchant_not_found
            'https://midtrans-service.test/api/v1/internal/merchants/larissama/payments/sandbox/*' => Http::response([
                'success' => false,
                'error' => [
                    'code' => 'merchant_not_found',
                    'message' => 'Merchant not found',
                ],
            ], 404),
            // Direct Midtrans fallback succeeds
            "https://api.sandbox.midtrans.com/v2/{$orderId}/status" => Http::response([
                'status_code' => '200',
                'status_message' => 'Success, transaction is found',
                'transaction_id' => $uuid,
                'order_id' => $orderId,
                'gross_amount' => '10000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'expire',
                'expiry_time' => '2026-11-02 22:02:43',
            ], 200),
        ]);

        $this->getJson("/api/v1/payment-results/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.status', 'expired')
            ->assertJsonPath('data.reference', $uuid);

        $attempt->refresh();
        $this->assertSame(PaymentAttempt::StatusExpired, $attempt->status);
        $this->assertSame('2026-11-02 15:02:43', $attempt->checkout_expires_at->format('Y-m-d H:i:s'));
    }

    private function paymentAttempt(): PaymentAttempt
    {
        return PaymentAttempt::factory()->create([
            'amount' => '10000.00',
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusPending,
            'merchant_reference' => 'INV-RESULT-A1',
            'gateway_merchant_code' => 'udev-demo',
            'gateway_environment' => 'sandbox',
            'gateway_payment_id' => (string) Str::ulid(),
        ]);
    }

    /** @return array<string, mixed> */
    private function gatewayPayment(PaymentAttempt $paymentAttempt, string $status): array
    {
        return [
            'id' => $paymentAttempt->gateway_payment_id,
            'merchant_order_id' => $paymentAttempt->merchant_reference,
            'amount_minor' => 10000,
            'currency' => 'IDR',
            'status' => $status,
            'paid_at' => $status === 'paid' ? now()->toISOString() : null,
        ];
    }
}
