<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\ApplicationPaymentGateway;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\PaymentAttempt;
use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DanaWebhookSecurityRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.dana_gateway.environment' => 'sandbox']);
        config(['services.dana_gateway.base_urls.sandbox' => 'http://dana-gateway.test']);
        config(['services.dana_gateway.merchant_api_keys' => [
            'sandbox' => ['udev-central' => 'central-valid-api-key'],
        ]]);
        config(['services.dana_gateway.merchants' => [
            'sandbox' => [
                'udev-central' => [
                    'name' => 'UDEV Central',
                    'is_active' => true,
                ],
            ],
        ]]);
        config(['services.dana_gateway.webhook_secret' => 'configured-webhook-secret-token-64char']);
        config(['services.udev_billing.subscription_dana_sandbox_merchant_code' => 'udev-central']);
    }

    public function test_unauthenticated_webhook_request_is_rejected_with_401_and_never_settles_invoice(): void
    {
        $invoice = $this->createPendingInvoice(100_000);
        $attempt = PaymentAttempt::factory()->for($invoice)->create([
            'attempt_number' => 1,
            'provider' => 'dana',
            'merchant_reference' => $invoice->invoice_number.'-A1',
            'gateway_merchant_code' => 'udev-central',
            'gateway_environment' => 'sandbox',
            'gateway_payment_id' => '01JBK9Y8Z123456789ABCDEF01',
            'status' => PaymentAttempt::StatusPending,
            'amount' => 100_000,
            'currency' => 'IDR',
        ]);

        $payload = [
            'merchant_order_id' => $invoice->invoice_number.'-A1',
            'originalReferenceNo' => '01JBK9Y8Z123456789ABCDEF01',
            'latestTransactionStatus' => '00',
            'status' => 'paid',
            'amount_minor' => 100000,
            'paid_at' => now()->toIso8601String(),
        ];

        // 1. Test POST ke /api/v1/payment-gateways/dana/webhook tanpa autentikasi
        $response = $this->postJson('/api/v1/payment-gateways/dana/webhook', $payload);
        $response->assertStatus(401)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Akses ditolak. Webhook DANA memerlukan autentikasi yang valid.');

        $this->assertSame(Invoice::StatusPending, $invoice->fresh()->status);
        $this->assertNull($invoice->fresh()->paid_at);
        $this->assertSame(PaymentAttempt::StatusPending, $attempt->fresh()->status);
        $this->assertSame(0, PaymentAllocation::query()->where('invoice_id', $invoice->id)->count());

        // 2. Test POST ke alias route /api/v1/webhooks/dana tanpa autentikasi
        $responseAlias = $this->postJson('/api/v1/webhooks/dana', $payload);
        $responseAlias->assertStatus(401)
            ->assertJsonPath('status', 'error');

        $this->assertSame(Invoice::StatusPending, $invoice->fresh()->status);
    }

    public function test_webhook_with_invalid_token_is_rejected_with_401(): void
    {
        $invoice = $this->createPendingInvoice(100_000);
        $attempt = PaymentAttempt::factory()->for($invoice)->create([
            'attempt_number' => 1,
            'provider' => 'dana',
            'merchant_reference' => $invoice->invoice_number.'-A1',
            'gateway_merchant_code' => 'udev-central',
            'gateway_environment' => 'sandbox',
            'gateway_payment_id' => '01JBK9Y8Z123456789ABCDEF02',
            'status' => PaymentAttempt::StatusPending,
            'amount' => 100_000,
            'currency' => 'IDR',
        ]);

        $payload = [
            'merchant_order_id' => $invoice->invoice_number.'-A1',
            'status' => 'paid',
        ];

        $response = $this->postJson('/api/v1/payment-gateways/dana/webhook', $payload, [
            'Authorization' => 'Bearer completely-invalid-token-12345',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('status', 'error');

        $this->assertSame(Invoice::StatusPending, $invoice->fresh()->status);
        $this->assertSame(PaymentAttempt::StatusPending, $attempt->fresh()->status);
    }

    public function test_authenticated_webhook_rejects_settlement_when_gateway_reports_pending_status(): void
    {
        $invoice = $this->createPendingInvoice(150_000);
        $attempt = PaymentAttempt::factory()->for($invoice)->create([
            'attempt_number' => 1,
            'provider' => 'dana',
            'merchant_reference' => $invoice->invoice_number.'-A1',
            'gateway_merchant_code' => 'udev-central',
            'gateway_environment' => 'sandbox',
            'gateway_payment_id' => '01JBK9Y8Z123456789ABCDEF03',
            'status' => PaymentAttempt::StatusPending,
            'amount' => 150_000,
            'currency' => 'IDR',
        ]);

        // Mock gateway response yang sebenarnya masih pending (spoofed payload mengatakan paid)
        Http::fake([
            'http://dana-gateway.test/api/v1/payments/*' => Http::response([
                'data' => [
                    'id' => '01JBK9Y8Z123456789ABCDEF03',
                    'merchant_order_id' => $invoice->invoice_number.'-A1',
                    'amount_minor' => 150000,
                    'currency' => 'IDR',
                    'status' => 'pending', // Nyata di gateway: belum lunas
                ],
            ], 200),
        ]);

        $payload = [
            'merchant_order_id' => $invoice->invoice_number.'-A1',
            'originalReferenceNo' => '01JBK9Y8Z123456789ABCDEF03',
            'status' => 'paid', // Payload palsu / unverified
            'latestTransactionStatus' => '00',
        ];

        $response = $this->postJson('/api/v1/payment-gateways/dana/webhook', $payload, [
            'Authorization' => 'Bearer configured-webhook-secret-token-64char',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Status pembayaran belum terverifikasi lunas oleh gateway DANA.');

        $this->assertSame(Invoice::StatusPending, $invoice->fresh()->status);
        $this->assertSame(PaymentAttempt::StatusPending, $attempt->fresh()->status);
        $this->assertSame(0, PaymentAllocation::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_authenticated_webhook_rejects_settlement_when_gateway_reports_amount_mismatch(): void
    {
        $invoice = $this->createPendingInvoice(200_000);
        $attempt = PaymentAttempt::factory()->for($invoice)->create([
            'attempt_number' => 1,
            'provider' => 'dana',
            'merchant_reference' => $invoice->invoice_number.'-A1',
            'gateway_merchant_code' => 'udev-central',
            'gateway_environment' => 'sandbox',
            'gateway_payment_id' => '01JBK9Y8Z123456789ABCDEF04',
            'status' => PaymentAttempt::StatusPending,
            'amount' => 200_000,
            'currency' => 'IDR',
        ]);

        // Gateway response nominal berbeda (100.000 bukan 200.000)
        Http::fake([
            'http://dana-gateway.test/api/v1/payments/*' => Http::response([
                'data' => [
                    'id' => '01JBK9Y8Z123456789ABCDEF04',
                    'merchant_order_id' => $invoice->invoice_number.'-A1',
                    'amount_minor' => 100000, // mismatch
                    'currency' => 'IDR',
                    'status' => 'paid',
                    'paid_at' => now()->toISOString(),
                ],
            ], 200),
        ]);

        $payload = [
            'merchant_order_id' => $invoice->invoice_number.'-A1',
            'originalReferenceNo' => '01JBK9Y8Z123456789ABCDEF04',
            'status' => 'paid',
        ];

        $response = $this->postJson('/api/v1/payment-gateways/dana/webhook', $payload, [
            'Authorization' => 'Bearer central-valid-api-key',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Data nominal atau mata uang transaksi gateway tidak sesuai.');

        $this->assertSame(Invoice::StatusPending, $invoice->fresh()->status);
        $this->assertSame(PaymentAttempt::StatusPending, $attempt->fresh()->status);
    }

    public function test_authenticated_webhook_settles_invoice_and_ensures_gateway_verification_metadata_consistency(): void
    {
        $invoice = $this->createPendingInvoice(250_000);
        $attempt = PaymentAttempt::factory()->for($invoice)->create([
            'attempt_number' => 1,
            'provider' => 'dana',
            'merchant_reference' => $invoice->invoice_number.'-A1',
            'gateway_merchant_code' => 'udev-central',
            'gateway_environment' => 'sandbox',
            'gateway_payment_id' => '01JBK9Y8Z123456789ABCDEF05',
            'status' => PaymentAttempt::StatusPending,
            'amount' => 250_000,
            'currency' => 'IDR',
        ]);

        $paidTimestamp = now()->toImmutable();

        Http::fake([
            'http://dana-gateway.test/api/v1/payments/*' => Http::response([
                'data' => [
                    'id' => '01JBK9Y8Z123456789ABCDEF05',
                    'merchant_order_id' => $invoice->invoice_number.'-A1',
                    'provider_order_id' => 'DANA-ORDER-REF-8899',
                    'amount_minor' => 250000,
                    'currency' => 'IDR',
                    'status' => 'paid',
                    'paid_at' => $paidTimestamp->toISOString(),
                ],
            ], 200),
        ]);

        $payload = [
            'merchant_order_id' => $invoice->invoice_number.'-A1',
            'originalReferenceNo' => '01JBK9Y8Z123456789ABCDEF05',
            'status' => 'paid',
            'latestTransactionStatus' => '00',
        ];

        // Autentikasi menggunakan X-Webhook-Secret header
        $response = $this->postJson('/api/v1/payment-gateways/dana/webhook', $payload, [
            'X-Webhook-Secret' => 'configured-webhook-secret-token-64char',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'OK')
            ->assertJsonPath('merchant_reference', $invoice->invoice_number.'-A1');

        $this->assertSame(Invoice::StatusPaid, $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->paid_at);

        $attemptRefreshed = $attempt->fresh();
        $this->assertSame(PaymentAttempt::StatusSucceeded, $attemptRefreshed->status);

        // Verifikasi konsistensi metadata gateway_verification
        $this->assertIsArray($attemptRefreshed->metadata);
        $this->assertArrayHasKey('gateway_verification', $attemptRefreshed->metadata);
        $this->assertSame('dana', $attemptRefreshed->metadata['gateway_verification']['provider']);
        $this->assertSame('01JBK9Y8Z123456789ABCDEF05', $attemptRefreshed->metadata['gateway_verification']['gateway_payment_id']);
        $this->assertNotEmpty($attemptRefreshed->metadata['gateway_verification']['verified_at']);

        // Verifikasi bahwa PaymentResultController sekarang melaporkan status 'paid' berkat konsistensi metadata
        $resultResponse = $this->getJson("/api/v1/payment-results/{$attemptRefreshed->gateway_payment_id}");
        $resultResponse->assertOk()
            ->assertJsonPath('data.status', 'paid');
    }

    public function test_authenticated_webhook_supports_merchant_api_key_and_x_service_token(): void
    {
        $invoice = $this->createPendingInvoice(50_000);
        $attempt = PaymentAttempt::factory()->for($invoice)->create([
            'attempt_number' => 1,
            'provider' => 'dana',
            'merchant_reference' => $invoice->invoice_number.'-A1',
            'gateway_merchant_code' => 'udev-central',
            'gateway_environment' => 'sandbox',
            'gateway_payment_id' => '01JBK9Y8Z123456789ABCDEF06',
            'status' => PaymentAttempt::StatusPending,
            'amount' => 50_000,
            'currency' => 'IDR',
        ]);

        Http::fake([
            'http://dana-gateway.test/api/v1/payments/*' => Http::response([
                'data' => [
                    'id' => '01JBK9Y8Z123456789ABCDEF06',
                    'merchant_order_id' => $invoice->invoice_number.'-A1',
                    'amount_minor' => 50000,
                    'currency' => 'IDR',
                    'status' => 'paid',
                    'paid_at' => now()->toISOString(),
                ],
            ], 200),
        ]);

        $payload = [
            'merchant_order_id' => $invoice->invoice_number.'-A1',
            'status' => 'paid',
        ];

        // Autentikasi menggunakan header X-Service-Token dengan merchant API key
        $response = $this->postJson('/api/v1/payment-gateways/dana/webhook', $payload, [
            'X-Service-Token' => 'central-valid-api-key',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'OK');

        $this->assertSame(Invoice::StatusPaid, $invoice->fresh()->status);
        $this->assertSame(PaymentAttempt::StatusSucceeded, $attempt->fresh()->status);
    }

    private function createPendingInvoice(float $amount): Invoice
    {
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->for($application)->create();
        $plan = SubscriptionPlan::factory()->for($application)->create(['price' => $amount]);

        ApplicationPaymentGateway::query()->create([
            'application_id' => $application->id,
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
        ]);
    }
}
