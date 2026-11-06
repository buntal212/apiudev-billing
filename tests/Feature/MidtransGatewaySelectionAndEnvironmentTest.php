<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\ApplicationPaymentGateway;
use App\Models\Invoice;
use App\Models\MidtransEnvironmentSetting;
use App\Models\MidtransMerchantCredential;
use App\Models\SubscriptionPlan;
use App\Services\MidtransSecretStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MidtransGatewaySelectionAndEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    private MidtransSecretStore $secretStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secretStore = app(MidtransSecretStore::class);
    }

    public function test_invoice_preview_dynamically_reflects_active_environment(): void
    {
        CarbonImmutable::setTestNow('2026-11-06 10:00:00');

        [$app, $token] = $this->createApplicationWithToken('Larissama');

        $customer = ApplicationCustomer::factory()->create([
            'application_id' => $app->id,
            'external_customer_id' => 'WARUNG-ENV-01',
        ]);

        $plan = SubscriptionPlan::factory()->create([
            'application_id' => $app->id,
            'code' => 'WARUNG-PRO',
            'price' => '175000.00',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        // 1. Setup Sandbox Mappings
        ApplicationPaymentGateway::query()->create([
            'application_id' => $app->id,
            'gateway_merchant_code' => 'larissama-sb',
            'provider' => 'midtrans',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);

        ApplicationPaymentGateway::query()->create([
            'application_id' => $app->id,
            'gateway_merchant_code' => 'larissama-dana-sb',
            'provider' => 'dana',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);

        // 2. Setup Production Mapping
        ApplicationPaymentGateway::query()->create([
            'application_id' => $app->id,
            'gateway_merchant_code' => 'larissama-prod',
            'provider' => 'midtrans',
            'environment' => 'production',
            'is_active' => true,
        ]);

        // A. Test Preview in Sandbox
        MidtransEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'midtrans'],
            ['active_environment' => 'sandbox']
        );

        $responseSandbox = $this->withToken($token)
            ->postJson("/api/v1/integrations/customers/{$customer->external_customer_id}/invoice-preview", [
                'subscription_plan_code' => $plan->code,
                'period_count' => 1,
            ]);

        $responseSandbox->assertOk()
            ->assertJsonCount(2, 'data.available_gateways')
            ->assertJsonPath('data.available_gateways.0.merchant_code', 'larissama-sb')
            ->assertJsonPath('data.available_gateways.1.merchant_code', 'larissama-dana-sb');

        // B. Switch Active Environment to Production and Test Preview
        MidtransEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'midtrans'],
            ['active_environment' => 'production']
        );

        $responseProd = $this->withToken($token)
            ->postJson("/api/v1/integrations/customers/{$customer->external_customer_id}/invoice-preview", [
                'subscription_plan_code' => $plan->code,
                'period_count' => 1,
            ]);

        $responseProd->assertOk()
            ->assertJsonCount(1, 'data.available_gateways')
            ->assertJsonPath('data.available_gateways.0.provider', 'midtrans')
            ->assertJsonPath('data.available_gateways.0.merchant_code', 'larissama-prod');
    }

    public function test_checkout_generic_qris_resolves_to_midtrans_when_midtrans_is_mapped_and_active(): void
    {
        CarbonImmutable::setTestNow('2026-11-06 10:00:00');

        [$app, $token] = $this->createApplicationWithToken('Larissama');
        $this->createMidtransMerchant('larissama', 'SB-Mid-server-testkey', $app, 'sandbox');

        // Application also has active DANA mapping
        ApplicationPaymentGateway::query()->create([
            'application_id' => $app->id,
            'gateway_merchant_code' => 'larissama-dana',
            'provider' => 'dana',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);

        $customer = ApplicationCustomer::factory()->create([
            'application_id' => $app->id,
            'external_customer_id' => 'WARUNG-QRIS-01',
        ]);

        $plan = SubscriptionPlan::factory()->create([
            'application_id' => $app->id,
            'price' => '100000.00',
            'duration_days' => 30,
        ]);

        $invoiceRes = $this->withToken($token)
            ->postJson("/api/v1/integrations/customers/{$customer->external_customer_id}/invoices", [
                'subscription_plan_code' => $plan->code,
                'period_count' => 1,
                'due_at' => now()->addDays(2)->toISOString(),
            ], ['Idempotency-Key' => 'inv-qris-test-1'])
            ->assertCreated();

        $invoiceNumber = $invoiceRes->json('data.invoice_number');

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'order_id' => $invoiceNumber.'-A1',
                'gross_amount' => '100000.00',
                'payment_type' => 'qris',
                'qr_string' => '00020101021226590014ID.LINKAJA.WWW0118',
                'transaction_status' => 'pending',
                'transaction_id' => 'midtrans-trx-qris-01',
            ], 201),
        ]);

        // Checkout without provider field, only payment_method = 'qris'
        $checkoutRes = $this->withToken($token)
            ->postJson("/api/v1/integrations/invoices/{$invoiceNumber}/checkout", [
                'payment_method' => 'qris',
                'idempotency_key' => 'chk-qris-test-1',
            ]);

        $checkoutRes->assertOk()
            ->assertJsonPath('data.payment.provider', 'midtrans')
            ->assertJsonPath('data.payment.payment_method', 'qris')
            ->assertJsonPath('data.payment.qr_content', '00020101021226590014ID.LINKAJA.WWW0118');

        $this->assertDatabaseHas('payment_attempts', [
            'merchant_reference' => $invoiceNumber.'-A1',
            'provider' => 'midtrans',
            'gateway_merchant_code' => 'larissama',
        ]);
    }

    public function test_checkout_midtrans_exclusive_methods_route_to_midtrans(): void
    {
        CarbonImmutable::setTestNow('2026-11-06 10:00:00');

        [$app, $token] = $this->createApplicationWithToken('Larissama');
        $this->createMidtransMerchant('larissama', 'SB-Mid-server-testkey', $app, 'sandbox');

        $customer = ApplicationCustomer::factory()->create([
            'application_id' => $app->id,
            'external_customer_id' => 'WARUNG-BRI-01',
        ]);

        $plan = SubscriptionPlan::factory()->create([
            'application_id' => $app->id,
            'price' => '100000.00',
            'duration_days' => 30,
        ]);

        $invoiceRes = $this->withToken($token)
            ->postJson("/api/v1/integrations/customers/{$customer->external_customer_id}/invoices", [
                'subscription_plan_code' => $plan->code,
                'due_at' => now()->addDays(2)->toISOString(),
            ], ['Idempotency-Key' => 'inv-bri-test-1'])
            ->assertCreated();

        $invoiceNumber = $invoiceRes->json('data.invoice_number');

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'order_id' => $invoiceNumber.'-A1',
                'gross_amount' => '100000.00',
                'payment_type' => 'bank_transfer',
                'transaction_status' => 'pending',
                'transaction_id' => 'midtrans-trx-bri-01',
                'va_numbers' => [
                    ['bank' => 'bri', 'va_number' => '123456789012345'],
                ],
            ], 201),
        ]);

        // Checkout with va_bri and NO provider field
        $checkoutRes = $this->withToken($token)
            ->postJson("/api/v1/integrations/invoices/{$invoiceNumber}/checkout", [
                'payment_method' => 'va_bri',
                'idempotency_key' => 'chk-bri-test-1',
            ]);

        $checkoutRes->assertOk()
            ->assertJsonPath('data.payment.provider', 'midtrans')
            ->assertJsonPath('data.payment.payment_method', 'va_bri')
            ->assertJsonPath('data.payment.payment_code', '123456789012345');
    }

    public function test_checkout_without_payment_method_or_provider_defaults_to_midtrans_when_mapped(): void
    {
        CarbonImmutable::setTestNow('2026-11-06 10:00:00');

        [$app, $token] = $this->createApplicationWithToken('Larissama');
        $this->createMidtransMerchant('larissama', 'SB-Mid-server-testkey', $app, 'sandbox');

        $customer = ApplicationCustomer::factory()->create([
            'application_id' => $app->id,
            'external_customer_id' => 'WARUNG-DEF-01',
        ]);

        $plan = SubscriptionPlan::factory()->create([
            'application_id' => $app->id,
            'price' => '100000.00',
            'duration_days' => 30,
        ]);

        $invoiceRes = $this->withToken($token)
            ->postJson("/api/v1/integrations/customers/{$customer->external_customer_id}/invoices", [
                'subscription_plan_code' => $plan->code,
                'due_at' => now()->addDays(2)->toISOString(),
            ], ['Idempotency-Key' => 'inv-def-test-1'])
            ->assertCreated();

        $invoiceNumber = $invoiceRes->json('data.invoice_number');

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'order_id' => $invoiceNumber.'-A1',
                'gross_amount' => '100000.00',
                'payment_type' => 'qris',
                'qr_string' => '00020101021226590014ID.DEFAULT.QRIS',
                'transaction_status' => 'pending',
                'transaction_id' => 'midtrans-trx-def-01',
            ], 201),
        ]);

        // Checkout with ONLY idempotency_key (no provider, no payment_method)
        $checkoutRes = $this->withToken($token)
            ->postJson("/api/v1/integrations/invoices/{$invoiceNumber}/checkout", [
                'idempotency_key' => 'chk-def-test-1',
            ]);

        $checkoutRes->assertOk()
            ->assertJsonPath('data.payment.provider', 'midtrans')
            ->assertJsonPath('data.payment.payment_method', 'qris');
    }

    public function test_checkout_environment_isolation_uses_credentials_of_active_environment(): void
    {
        CarbonImmutable::setTestNow('2026-11-06 10:00:00');

        [$app, $token] = $this->createApplicationWithToken('Larissama');
        $this->createMidtransMerchant('larissama-sb', 'SB-Mid-server-sandboxkey', $app, 'sandbox');
        $this->createMidtransMerchant('larissama-prod', 'Mid-server-productionkey', $app, 'production');

        $customer = ApplicationCustomer::factory()->create([
            'application_id' => $app->id,
            'external_customer_id' => 'WARUNG-ISOL-01',
        ]);

        $plan = SubscriptionPlan::factory()->create([
            'application_id' => $app->id,
            'price' => '100000.00',
            'duration_days' => 30,
        ]);

        $invoiceRes = $this->withToken($token)
            ->postJson("/api/v1/integrations/customers/{$customer->external_customer_id}/invoices", [
                'subscription_plan_code' => $plan->code,
                'due_at' => now()->addDays(2)->toISOString(),
            ], ['Idempotency-Key' => 'inv-isol-test-1'])
            ->assertCreated();

        $invoiceNumber = $invoiceRes->json('data.invoice_number');

        // Activate Production
        MidtransEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'midtrans'],
            ['active_environment' => 'production']
        );

        Http::fake([
            'https://api.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'order_id' => $invoiceNumber.'-A1',
                'gross_amount' => '100000.00',
                'payment_type' => 'qris',
                'qr_string' => '00020101021226590014ID.PRODUCTION.QRIS',
                'transaction_status' => 'pending',
                'transaction_id' => 'midtrans-trx-prod-01',
            ], 201),
        ]);

        $checkoutRes = $this->withToken($token)
            ->postJson("/api/v1/integrations/invoices/{$invoiceNumber}/checkout", [
                'provider' => 'midtrans',
                'payment_method' => 'qris',
                'idempotency_key' => 'chk-isol-test-1',
            ]);

        $checkoutRes->assertOk()
            ->assertJsonPath('data.payment.provider', 'midtrans');

        $attempt = \App\Models\PaymentAttempt::query()->where('merchant_reference', $invoiceNumber.'-A1')->sole();
        $this->assertSame('production', $attempt->gateway_environment);
        $this->assertSame('larissama-prod', $attempt->gateway_merchant_code);
        $this->assertSame('production', $attempt->metadata['environment_snapshot']['environment']);
        $this->assertSame('larissama-prod', $attempt->metadata['environment_snapshot']['merchant_code']);
    }

    public function test_checkout_idempotency_returns_identical_midtrans_attempt_without_duplication(): void
    {
        CarbonImmutable::setTestNow('2026-11-06 10:00:00');

        [$app, $token] = $this->createApplicationWithToken('Larissama');
        $this->createMidtransMerchant('larissama-sb', 'SB-Mid-server-TEST123456789', $app, 'sandbox');

        $customer = ApplicationCustomer::factory()->create([
            'application_id' => $app->id,
            'external_customer_id' => 'WARUNG-IDEMP-01',
        ]);

        $plan = SubscriptionPlan::factory()->create([
            'application_id' => $app->id,
            'code' => 'WARUNG-BASIC',
            'price' => '100000.00',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $invoiceRes = $this->withToken($token)
            ->postJson("/api/v1/integrations/customers/{$customer->external_customer_id}/invoices", [
                'subscription_plan_code' => $plan->code,
                'due_at' => now()->addDays(2)->toISOString(),
            ], ['Idempotency-Key' => 'inv-idemp-test-1'])
            ->assertCreated();

        $invoiceNumber = $invoiceRes->json('data.invoice_number');

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'order_id' => $invoiceNumber.'-A1',
                'gross_amount' => '100000.00',
                'payment_type' => 'qris',
                'qr_string' => '00020101021226590014ID.IDEMP.QRIS',
                'transaction_status' => 'pending',
                'transaction_id' => 'midtrans-trx-idemp-01',
            ], 201),
        ]);

        // First checkout request
        $res1 = $this->withToken($token)
            ->postJson("/api/v1/integrations/invoices/{$invoiceNumber}/checkout", [
                'provider' => 'midtrans',
                'payment_method' => 'qris',
                'idempotency_key' => 'chk-idemp-key-999',
            ])->assertOk();

        // Second checkout request with the same idempotency key
        $res2 = $this->withToken($token)
            ->postJson("/api/v1/integrations/invoices/{$invoiceNumber}/checkout", [
                'provider' => 'midtrans',
                'payment_method' => 'qris',
                'idempotency_key' => 'chk-idemp-key-999',
            ])->assertOk();

        // Assert responses are identical
        $this->assertSame($res1->json('data.payment.transaction_id'), $res2->json('data.payment.transaction_id'));
        $this->assertSame($res1->json('data.payment.qr_content'), $res2->json('data.payment.qr_content'));

        // Assert exactly one PaymentAttempt exists
        $invoice = Invoice::query()->where('invoice_number', $invoiceNumber)->sole();
        $attempts = \App\Models\PaymentAttempt::query()->where('invoice_id', $invoice->id)->get();
        $this->assertCount(1, $attempts);
    }

    public function test_historical_sandbox_transaction_retains_sandbox_environment_after_production_switch(): void
    {
        CarbonImmutable::setTestNow('2026-11-06 10:00:00');

        [$app, $token] = $this->createApplicationWithToken('Larissama');
        $this->createMidtransMerchant('larissama-sb', 'SB-Mid-server-SANDBOX-KEY', $app, 'sandbox');
        $this->createMidtransMerchant('larissama-prod', 'Mid-server-PRODUCTION-KEY', $app, 'production');

        $customer = ApplicationCustomer::factory()->create([
            'application_id' => $app->id,
            'external_customer_id' => 'WARUNG-HIST-01',
        ]);

        $plan = SubscriptionPlan::factory()->create([
            'application_id' => $app->id,
            'code' => 'WARUNG-BASIC',
            'price' => '100000.00',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        // 1. Initiate checkout while in Sandbox
        MidtransEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'midtrans'],
            ['active_environment' => 'sandbox']
        );

        $invoiceRes = $this->withToken($token)
            ->postJson("/api/v1/integrations/customers/{$customer->external_customer_id}/invoices", [
                'subscription_plan_code' => $plan->code,
                'due_at' => now()->addDays(2)->toISOString(),
            ], ['Idempotency-Key' => 'inv-hist-test-1'])
            ->assertCreated();

        $invoiceNumber = $invoiceRes->json('data.invoice_number');

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'order_id' => $invoiceNumber.'-A1',
                'gross_amount' => '100000.00',
                'payment_type' => 'qris',
                'qr_string' => '00020101021226590014ID.SANDBOX.QRIS',
                'transaction_status' => 'pending',
                'transaction_id' => 'midtrans-trx-sb-hist-01',
            ], 201),
        ]);

        $this->withToken($token)
            ->postJson("/api/v1/integrations/invoices/{$invoiceNumber}/checkout", [
                'provider' => 'midtrans',
                'payment_method' => 'qris',
                'idempotency_key' => 'chk-hist-key-001',
            ])->assertOk();

        $attempt = \App\Models\PaymentAttempt::query()->where('merchant_reference', $invoiceNumber.'-A1')->sole();
        $this->assertSame('sandbox', $attempt->gateway_environment);

        // 2. Operator switches active environment to Production
        MidtransEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'midtrans'],
            ['active_environment' => 'production']
        );

        // 3. Webhook arrives for the sandbox order
        $serverKeySandbox = 'SB-Mid-server-SANDBOX-KEY';
        $signature = hash('sha512', ($invoiceNumber.'-A1').'200'.'100000.00'.$serverKeySandbox);

        $webhookPayload = [
            'order_id' => $invoiceNumber.'-A1',
            'status_code' => '200',
            'gross_amount' => '100000.00',
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'transaction_id' => 'midtrans-trx-sb-hist-01',
            'settlement_time' => '2026-11-06 10:05:00',
        ];

        $webhookRes = $this->postJson('/api/v1/webhooks/midtrans', $webhookPayload);
        $webhookRes->assertOk();

        // 4. Assert the attempt and settlement remain correctly associated with sandbox
        $attempt->refresh();
        $this->assertSame('succeeded', $attempt->status);
        $this->assertSame('sandbox', $attempt->gateway_environment);
        $this->assertSame('larissama-sb', $attempt->gateway_merchant_code);
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

    private function createMidtransMerchant(string $merchantCode, string $serverKey, Application $application, string $environment = 'sandbox'): void
    {
        $enc = $this->secretStore->encryptSecret($serverKey, $merchantCode, 'server_key', 'v1', $environment);

        MidtransMerchantCredential::query()->create([
            'merchant_code' => $merchantCode,
            'merchant_name' => "Merchant {$merchantCode}",
            'environment' => $environment,
            'is_active' => true,
            'encrypted_server_key' => $enc['ciphertext'],
            'server_key_nonce' => $enc['nonce'],
            'server_key_tag' => $enc['tag'],
            'server_key_version' => $enc['key_version'],
        ]);

        ApplicationPaymentGateway::query()->create([
            'application_id' => $application->id,
            'gateway_merchant_code' => $merchantCode,
            'provider' => 'midtrans',
            'environment' => $environment,
            'is_active' => true,
        ]);
    }
}
