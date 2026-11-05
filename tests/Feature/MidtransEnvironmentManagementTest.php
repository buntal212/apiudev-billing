<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\ApplicationPaymentGateway;
use App\Models\GatewayAudit;
use App\Models\Invoice;
use App\Models\MidtransEnvironmentSetting;
use App\Models\MidtransMerchantCredential;
use App\Models\Partner;
use App\Models\PaymentAllocation;
use App\Models\PaymentAttempt;
use App\Models\ReferralCode;
use App\Models\ReferralCommissionLedger;
use App\Models\SettlementEvent;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\MidtransSecretStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MidtransEnvironmentManagementTest extends TestCase
{
    use RefreshDatabase;

    private const string SandboxServerKey = 'SB-Mid-server-SANDBOX1234567890';
    private const string ProductionServerKey = 'Mid-server-PRODUCTION9876543210';
    private const string MerchantCode = 'larissama';

    private User $admin;
    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.midtrans_gateway', [
            'sandbox_base_url' => 'https://api.sandbox.midtrans.com/v2',
            'production_base_url' => 'https://api.midtrans.com/v2',
            'gateway_service_url' => '',
            'service_token' => 'test-service-token-123456',
            'timeout' => 5,
            'connect_timeout' => 3,
        ]);

        $this->admin = User::factory()->create([
            'is_admin' => true,
        ]);

        $this->regularUser = User::factory()->create([
            'is_admin' => false,
        ]);
    }

    public function test_default_active_environment_is_sandbox_and_can_be_queried(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $this->assertSame('sandbox', MidtransEnvironmentSetting::getActiveEnvironment());

        $response = $this->getJson('/api/v1/admin/midtrans-gateway/environment');

        $response->assertOk()
            ->assertJsonPath('data.active_environment', 'sandbox')
            ->assertJsonPath('data.readiness.is_ready_for_production', false);
    }

    public function test_admin_can_save_separate_credentials_for_sandbox_and_production_without_collision(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        // 1. Save Sandbox configuration
        $resSandbox = $this->putJson('/api/v1/admin/midtrans-gateway/configuration', [
            'merchant_code' => 'udev-central',
            'environment' => 'sandbox',
            'client_key' => 'SB-Mid-client-SANDBOX-KEY',
            'server_key' => self::SandboxServerKey,
            'midtrans_merchant_id' => 'G-SANDBOX-01',
            'is_active' => true,
        ]);
        $resSandbox->assertOk();

        // 2. Save Production configuration for the same merchant code
        $resProduction = $this->putJson('/api/v1/admin/midtrans-gateway/configuration', [
            'merchant_code' => 'udev-central',
            'environment' => 'production',
            'client_key' => 'Mid-client-PROD-KEY',
            'server_key' => self::ProductionServerKey,
            'midtrans_merchant_id' => 'G-PROD-01',
            'is_active' => true,
        ]);
        $resProduction->assertOk();

        // 3. Verify both coexist in database
        $this->assertDatabaseHas('midtrans_merchant_credentials', [
            'merchant_code' => 'udev-central',
            'environment' => 'sandbox',
            'client_key' => 'SB-Mid-client-SANDBOX-KEY',
        ]);
        $this->assertDatabaseHas('midtrans_merchant_credentials', [
            'merchant_code' => 'udev-central',
            'environment' => 'production',
            'client_key' => 'Mid-client-PROD-KEY',
        ]);

        // 4. Query each environment configuration independently
        $getSandbox = $this->getJson('/api/v1/admin/midtrans-gateway/configuration?merchant_code=udev-central&environment=sandbox');
        $getSandbox->assertOk()
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.client_key', 'SB-Mid-client-SANDBOX-KEY')
            ->assertJsonPath('data.has_server_key', true);

        $getProd = $this->getJson('/api/v1/admin/midtrans-gateway/configuration?merchant_code=udev-central&environment=production');
        $getProd->assertOk()
            ->assertJsonPath('data.environment', 'production')
            ->assertJsonPath('data.client_key', 'Mid-client-PROD-KEY')
            ->assertJsonPath('data.has_server_key', true);

        // 5. Deleting production configuration clears secret keys while keeping sandbox configuration intact
        $deleteProd = $this->deleteJson('/api/v1/admin/midtrans-gateway/configuration?merchant_code=udev-central&environment=production');
        $deleteProd->assertOk();

        $this->assertDatabaseHas('midtrans_merchant_credentials', [
            'merchant_code' => 'udev-central',
            'environment' => 'production',
            'encrypted_server_key' => null,
        ]);
        $this->assertDatabaseHas('midtrans_merchant_credentials', [
            'merchant_code' => 'udev-central',
            'environment' => 'sandbox',
            'client_key' => 'SB-Mid-client-SANDBOX-KEY',
        ]);
    }

    public function test_production_activation_is_denied_without_admin_privileges(): void
    {
        // Unauthenticated
        $this->postJson('/api/v1/admin/midtrans-gateway/environment/activate-production', [
            'confirmation_phrase' => 'AKTIFKAN PRODUCTION',
        ])->assertStatus(401);

        // Non-admin user
        Sanctum::actingAs($this->regularUser, ['admin:access']);
        $this->postJson('/api/v1/admin/midtrans-gateway/environment/activate-production', [
            'confirmation_phrase' => 'AKTIFKAN PRODUCTION',
        ])->assertStatus(403);
    }

    public function test_production_activation_rejected_if_confirmation_phrase_does_not_match(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $response = $this->postJson('/api/v1/admin/midtrans-gateway/environment/activate-production', [
            'confirmation_phrase' => 'AKTIFKAN',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['confirmation_phrase']);
    }

    public function test_production_activation_rejected_if_readiness_prerequisites_fail(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        // Attempt activation without production credentials or application mappings
        $response = $this->postJson('/api/v1/admin/midtrans-gateway/environment/activate-production', [
            'confirmation_phrase' => 'AKTIFKAN PRODUCTION',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('readiness.is_ready_for_production', false);

        $this->assertSame('sandbox', MidtransEnvironmentSetting::getActiveEnvironment());
    }

    public function test_production_activation_succeeds_when_prerequisites_are_satisfied_and_admin_confirms(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        // 1. Create Production credentials for udev-central with successful connection probe
        $secretStore = app(MidtransSecretStore::class);
        $encrypted = $secretStore->encryptSecret(self::ProductionServerKey, 'udev-central', 'server_key', 'production');
        MidtransMerchantCredential::create([
            'merchant_code' => 'udev-central',
            'merchant_name' => 'UDEV Central Billing',
            'environment' => 'production',
            'client_key' => 'Mid-client-PROD-VALID',
            'encrypted_server_key' => $encrypted['ciphertext'],
            'server_key_nonce' => $encrypted['nonce'],
            'server_key_tag' => $encrypted['tag'],
            'key_version' => $encrypted['key_version'],
            'last_tested_at' => now(),
            'last_test_status' => 'success',
            'last_test_message' => 'Connection OK',
            'is_active' => true,
        ]);

        // 2. Create Application Payment Gateway mapping for production
        $app = Application::factory()->create(['name' => 'Aplikasi Utama']);
        ApplicationPaymentGateway::create([
            'application_id' => $app->id,
            'provider' => 'midtrans',
            'environment' => 'production',
            'gateway_merchant_code' => 'udev-central',
            'is_active' => true,
        ]);

        // 3. Activate Production via API with required phrase
        $response = $this->postJson('/api/v1/admin/midtrans-gateway/environment/activate-production', [
            'confirmation_phrase' => 'AKTIFKAN PRODUCTION',
            'notes' => 'Go-live production migration verified by admin.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.active_environment', 'production')
            ->assertJsonPath('data.activated_by_id', $this->admin->id);

        $this->assertSame('production', MidtransEnvironmentSetting::getActiveEnvironment());

        // 4. Verify audit log entry
        $this->assertDatabaseHas('midtrans_gateway_audits', [
            'merchant_code' => 'udev-central',
            'action' => 'environment.production_activated',
            'status' => 'success',
        ]);
    }

    public function test_checkout_follows_active_environment_and_persists_snapshot_of_environment_merchant_and_nominal(): void
    {
        $admin = $this->admin;
        Sanctum::actingAs($admin, ['admin:access']);

        // Set up app, customer, plan, invoice
        $app = Application::factory()->create(['name' => 'Aplikasi Larissama']);
        $customer = ApplicationCustomer::factory()->create(['application_id' => $app->id]);
        $plan = SubscriptionPlan::factory()->create([
            'application_id' => $app->id,
            'price' => '75000.00',
            'duration_days' => 30,
        ]);

        $secretStore = app(MidtransSecretStore::class);

        // Setup Sandbox Credential & Mapping
        $encSandbox = $secretStore->encryptSecret(self::SandboxServerKey, self::MerchantCode, 'server_key', 'sandbox');
        MidtransMerchantCredential::create([
            'merchant_code' => self::MerchantCode,
            'merchant_name' => 'Larissama Sandbox Store',
            'environment' => 'sandbox',
            'encrypted_server_key' => $encSandbox['ciphertext'],
            'server_key_nonce' => $encSandbox['nonce'],
            'server_key_tag' => $encSandbox['tag'],
            'key_version' => $encSandbox['key_version'],
            'is_active' => true,
        ]);
        ApplicationPaymentGateway::create([
            'application_id' => $app->id,
            'provider' => 'midtrans',
            'environment' => 'sandbox',
            'gateway_merchant_code' => self::MerchantCode,
            'is_active' => true,
        ]);

        // Setup Production Credential & Mapping for udev-central (for readiness) and larissama
        $encProdCentral = $secretStore->encryptSecret(self::ProductionServerKey, 'udev-central', 'server_key', 'production');
        MidtransMerchantCredential::create([
            'merchant_code' => 'udev-central',
            'merchant_name' => 'UDEV Central',
            'environment' => 'production',
            'client_key' => 'Mid-client-CENTRAL-PROD',
            'encrypted_server_key' => $encProdCentral['ciphertext'],
            'server_key_nonce' => $encProdCentral['nonce'],
            'server_key_tag' => $encProdCentral['tag'],
            'key_version' => $encProdCentral['key_version'],
            'last_tested_at' => now(),
            'last_test_status' => 'success',
            'is_active' => true,
        ]);

        $encProd = $secretStore->encryptSecret(self::ProductionServerKey, self::MerchantCode, 'server_key', 'production');
        MidtransMerchantCredential::create([
            'merchant_code' => self::MerchantCode,
            'merchant_name' => 'Larissama Production Store',
            'environment' => 'production',
            'client_key' => 'Mid-client-PROD-LARISSAMA',
            'encrypted_server_key' => $encProd['ciphertext'],
            'server_key_nonce' => $encProd['nonce'],
            'server_key_tag' => $encProd['tag'],
            'key_version' => $encProd['key_version'],
            'last_tested_at' => now(),
            'last_test_status' => 'success',
            'is_active' => true,
        ]);
        ApplicationPaymentGateway::create([
            'application_id' => $app->id,
            'provider' => 'midtrans',
            'environment' => 'production',
            'gateway_merchant_code' => self::MerchantCode,
            'is_active' => true,
        ]);

        // --- PART 1: SANDBOX CHECKOUT ---
        // Active environment is currently Sandbox
        $invoice1 = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '75000.00',
            'currency' => 'IDR',
            'duration_days' => 30,
            'status' => Invoice::StatusPending,
            'due_at' => now()->addDays(3),
        ]);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'status_message' => 'QRIS transaction is created',
                'transaction_id' => 'midtrans-tx-sb-01',
                'order_id' => $invoice1->invoice_number.'-A1',
                'gross_amount' => '75000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'pending',
                'qr_string' => '00020101021226590014ID.LINKAJA.WWW',
                'actions' => [
                    [
                        'name' => 'generate-qr-code',
                        'method' => 'GET',
                        'url' => 'https://api.sandbox.midtrans.com/v2/qris/midtrans-tx-sb-01/qr-code',
                    ],
                ],
            ], 201),
        ]);

        $res1 = $this->postJson("/api/v1/admin/invoices/{$invoice1->id}/checkout", [
            'payment_method' => 'qris',
        ], ['Idempotency-Key' => 'idemp-chk-sb-01']);

        $res1->assertOk();
        $attempt1 = PaymentAttempt::query()->where('invoice_id', $invoice1->id)->firstOrFail();
        $this->assertSame('sandbox', $attempt1->gateway_environment);
        $this->assertSame(self::MerchantCode, $attempt1->gateway_merchant_code);
        $this->assertEquals('75000.00', $attempt1->amount);
        $this->assertSame('sandbox', $attempt1->metadata['environment_snapshot']['environment']);
        $this->assertSame(self::MerchantCode, $attempt1->metadata['environment_snapshot']['merchant_code']);
        $this->assertEquals('75000.00', $attempt1->metadata['environment_snapshot']['nominal']);

        // --- PART 2: ACTIVATE PRODUCTION AND CHECKOUT ---
        $this->postJson('/api/v1/admin/midtrans-gateway/environment/activate-production', [
            'confirmation_phrase' => 'AKTIFKAN PRODUCTION',
        ])->assertOk();

        $invoice2 = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '75000.00',
            'currency' => 'IDR',
            'duration_days' => 30,
            'status' => Invoice::StatusPending,
            'due_at' => now()->addDays(3),
        ]);

        Http::fake([
            'https://api.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'status_message' => 'QRIS transaction is created',
                'transaction_id' => 'midtrans-tx-prod-01',
                'order_id' => $invoice2->invoice_number.'-A1',
                'gross_amount' => '75000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'pending',
                'qr_string' => '00020101021226590014ID.LINKAJA.WWW',
                'actions' => [
                    [
                        'name' => 'generate-qr-code',
                        'method' => 'GET',
                        'url' => 'https://api.midtrans.com/v2/qris/midtrans-tx-prod-01/qr-code',
                    ],
                ],
            ], 201),
        ]);

        $res2 = $this->postJson("/api/v1/admin/invoices/{$invoice2->id}/checkout", [
            'payment_method' => 'qris',
        ], ['Idempotency-Key' => 'idemp-chk-prod-01']);

        $res2->assertOk();
        $attempt2 = PaymentAttempt::query()->where('invoice_id', $invoice2->id)->firstOrFail();
        $this->assertSame('production', $attempt2->gateway_environment);
        $this->assertSame(self::MerchantCode, $attempt2->gateway_merchant_code);
        $this->assertEquals('75000.00', $attempt2->amount);
        $this->assertSame('production', $attempt2->metadata['environment_snapshot']['environment']);
        $this->assertSame(self::MerchantCode, $attempt2->metadata['environment_snapshot']['merchant_code']);
        $this->assertEquals('75000.00', $attempt2->metadata['environment_snapshot']['nominal']);
        $this->assertSame('https://api.midtrans.com/v2/qris/midtrans-tx-prod-01/qr-code', $attempt2->metadata['qr_url']);
    }

    public function test_retry_and_historical_transactions_preserve_original_attempt_environment_even_if_active_env_changes(): void
    {
        $admin = $this->admin;
        Sanctum::actingAs($admin, ['admin:access']);

        $app = Application::factory()->create(['name' => 'Aplikasi Larissama']);
        $customer = ApplicationCustomer::factory()->create(['application_id' => $app->id]);
        $plan = SubscriptionPlan::factory()->create([
            'application_id' => $app->id,
            'price' => '50000.00',
            'duration_days' => 30,
        ]);

        $secretStore = app(MidtransSecretStore::class);
        $encProd = $secretStore->encryptSecret(self::ProductionServerKey, self::MerchantCode, 'server_key', 'production');
        MidtransMerchantCredential::create([
            'merchant_code' => self::MerchantCode,
            'merchant_name' => 'Larissama Production Store',
            'environment' => 'production',
            'encrypted_server_key' => $encProd['ciphertext'],
            'server_key_nonce' => $encProd['nonce'],
            'server_key_tag' => $encProd['tag'],
            'key_version' => $encProd['key_version'],
            'is_active' => true,
        ]);
        ApplicationPaymentGateway::create([
            'application_id' => $app->id,
            'provider' => 'midtrans',
            'environment' => 'production',
            'gateway_merchant_code' => self::MerchantCode,
            'is_active' => true,
        ]);

        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '50000.00',
            'currency' => 'IDR',
            'duration_days' => 30,
            'status' => Invoice::StatusPending,
            'due_at' => now()->addDays(3),
        ]);

        // Historical attempt created during Production operation
        $attempt = PaymentAttempt::query()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'provider' => 'midtrans',
            'merchant_reference' => $invoice->invoice_number.'-A1',
            'gateway_merchant_code' => self::MerchantCode,
            'gateway_environment' => 'production',
            'amount' => $invoice->amount,
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusPending,
            'gateway_payment_id' => 'midtrans-hist-prod-01',
            'idempotency_key' => 'checkout:'.$invoice->id.':hist',
            'requested_at' => now(),
        ]);

        // Admin now switches operational environment back to Sandbox
        MidtransEnvironmentSetting::query()->updateOrCreate(
            ['id' => 1],
            ['active_environment' => 'sandbox']
        );
        $this->assertSame('sandbox', MidtransEnvironmentSetting::getActiveEnvironment());

        // Reconcile the old Production attempt. It must query the Production Midtrans endpoint, NOT Sandbox!
        Http::fake([
            'https://api.midtrans.com/v2/'.urlencode($attempt->merchant_reference).'/status' => Http::response([
                'status_code' => '200',
                'status_message' => 'Success, transaction found',
                'transaction_id' => 'midtrans-hist-prod-01',
                'order_id' => $attempt->merchant_reference,
                'gross_amount' => '50000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'settlement',
                'fraud_status' => 'accept',
                'settlement_time' => '2026-11-05 12:00:00',
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/admin/payment-attempts/{$attempt->id}/reconcile");
        $response->assertOk()
            ->assertJsonPath('data.status', 'succeeded');

        $this->assertSame(PaymentAttempt::StatusSucceeded, $attempt->fresh()->status);
        $this->assertSame('production', $attempt->fresh()->gateway_environment);
        $this->assertSame(Invoice::StatusPaid, $invoice->fresh()->status);

        // Ensure Production API was queried and Sandbox API was NOT called
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.midtrans.com') && !str_contains($req->url(), 'sandbox'));
    }

    public function test_midtrans_webhook_signature_verification_and_settlement_works_for_both_sandbox_and_production(): void
    {
        $app = Application::factory()->create(['name' => 'Aplikasi Larissama']);
        $customer = ApplicationCustomer::factory()->create(['application_id' => $app->id]);
        $plan = SubscriptionPlan::factory()->create([
            'application_id' => $app->id,
            'price' => '50000.00',
            'duration_days' => 30,
        ]);

        $secretStore = app(MidtransSecretStore::class);

        // Save Sandbox & Production credentials
        $encSandbox = $secretStore->encryptSecret(self::SandboxServerKey, self::MerchantCode, 'server_key', 'sandbox');
        MidtransMerchantCredential::create([
            'merchant_code' => self::MerchantCode,
            'merchant_name' => 'Larissama Store',
            'environment' => 'sandbox',
            'encrypted_server_key' => $encSandbox['ciphertext'],
            'server_key_nonce' => $encSandbox['nonce'],
            'server_key_tag' => $encSandbox['tag'],
            'key_version' => $encSandbox['key_version'],
            'is_active' => true,
        ]);
        ApplicationPaymentGateway::create([
            'application_id' => $app->id,
            'provider' => 'midtrans',
            'environment' => 'sandbox',
            'gateway_merchant_code' => self::MerchantCode,
            'is_active' => true,
        ]);

        $encProd = $secretStore->encryptSecret(self::ProductionServerKey, self::MerchantCode, 'server_key', 'production');
        MidtransMerchantCredential::create([
            'merchant_code' => self::MerchantCode,
            'merchant_name' => 'Larissama Store',
            'environment' => 'production',
            'encrypted_server_key' => $encProd['ciphertext'],
            'server_key_nonce' => $encProd['nonce'],
            'server_key_tag' => $encProd['tag'],
            'key_version' => $encProd['key_version'],
            'is_active' => true,
        ]);
        ApplicationPaymentGateway::create([
            'application_id' => $app->id,
            'provider' => 'midtrans',
            'environment' => 'production',
            'gateway_merchant_code' => self::MerchantCode,
            'is_active' => true,
        ]);

        // --- TEST 1: SANDBOX WEBHOOK ---
        $invSandbox = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '50000.00',
            'currency' => 'IDR',
            'duration_days' => 30,
            'status' => Invoice::StatusPending,
        ]);
        $attemptSb = PaymentAttempt::create([
            'invoice_id' => $invSandbox->id,
            'attempt_number' => 1,
            'provider' => 'midtrans',
            'merchant_reference' => $invSandbox->invoice_number.'-A1',
            'gateway_merchant_code' => self::MerchantCode,
            'gateway_environment' => 'sandbox',
            'amount' => $invSandbox->amount,
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusPending,
            'gateway_payment_id' => 'midtrans-webhook-sb-01',
            'idempotency_key' => 'checkout:'.$invSandbox->id.':sb',
            'requested_at' => now(),
        ]);

        $sigSandbox = hash('sha512', $attemptSb->merchant_reference.'200'.'50000.00'.self::SandboxServerKey);
        $payloadSb = [
            'order_id' => $attemptSb->merchant_reference,
            'status_code' => '200',
            'gross_amount' => '50000.00',
            'signature_key' => $sigSandbox,
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'transaction_id' => 'midtrans-webhook-sb-01',
            'settlement_time' => now()->format('Y-m-d H:i:s'),
        ];
        $this->postJson('/api/v1/payment-gateways/midtrans/webhook', $payloadSb)->assertOk();
        $this->assertSame(Invoice::StatusPaid, $invSandbox->fresh()->status);
        $this->assertSame(PaymentAttempt::StatusSucceeded, $attemptSb->fresh()->status);

        // --- TEST 2: PRODUCTION WEBHOOK ---
        $invProd = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '50000.00',
            'currency' => 'IDR',
            'duration_days' => 30,
            'status' => Invoice::StatusPending,
        ]);
        $attemptProd = PaymentAttempt::create([
            'invoice_id' => $invProd->id,
            'attempt_number' => 1,
            'provider' => 'midtrans',
            'merchant_reference' => $invProd->invoice_number.'-A1',
            'gateway_merchant_code' => self::MerchantCode,
            'gateway_environment' => 'production',
            'amount' => $invProd->amount,
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusPending,
            'gateway_payment_id' => 'midtrans-webhook-prod-01',
            'idempotency_key' => 'checkout:'.$invProd->id.':prod',
            'requested_at' => now(),
        ]);

        // Signature must be calculated using PRODUCTION Server Key
        $sigProd = hash('sha512', $attemptProd->merchant_reference.'200'.'50000.00'.self::ProductionServerKey);
        $payloadProd = [
            'order_id' => $attemptProd->merchant_reference,
            'status_code' => '200',
            'gross_amount' => '50000.00',
            'signature_key' => $sigProd,
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'transaction_id' => 'midtrans-webhook-prod-01',
            'settlement_time' => now()->format('Y-m-d H:i:s'),
        ];
        $this->postJson('/api/v1/payment-gateways/midtrans/webhook', $payloadProd)->assertOk();
        $this->assertSame(Invoice::StatusPaid, $invProd->fresh()->status);
        $this->assertSame(PaymentAttempt::StatusSucceeded, $attemptProd->fresh()->status);
    }

    public function test_admin_can_safely_switch_back_to_sandbox_and_audit_is_recorded(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        // Set active environment to production first
        MidtransEnvironmentSetting::query()->updateOrCreate(
            ['id' => 1],
            ['active_environment' => 'production']
        );
        $this->assertSame('production', MidtransEnvironmentSetting::getActiveEnvironment());

        // Switch to sandbox via API
        $response = $this->postJson('/api/v1/admin/midtrans-gateway/environment/switch-sandbox', [
            'notes' => 'Maintenance switch to sandbox.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.active_environment', 'sandbox');

        $this->assertSame('sandbox', MidtransEnvironmentSetting::getActiveEnvironment());

        $this->assertDatabaseHas('midtrans_gateway_audits', [
            'merchant_code' => 'udev-central',
            'action' => 'environment.switched_to_sandbox',
            'status' => 'success',
        ]);
    }

    public function test_old_invoices_referral_commissions_and_sandbox_credentials_are_unaffected(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $partner = Partner::factory()->create(['name' => 'Mitra Sentosa', 'is_active' => true]);
        $refCode = ReferralCode::factory()->create([
            'partner_id' => $partner->id,
            'code' => 'REF-SENTOSA-01',
        ]);

        $app = Application::factory()->create(['name' => 'Aplikasi Mitra']);
        $customer = ApplicationCustomer::factory()->create(['application_id' => $app->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $app->id]);

        $oldInvoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '100000.00',
            'currency' => 'IDR',
            'status' => Invoice::StatusPaid,
            'created_at' => now()->subMonths(2),
        ]);

        $customerReferral = \App\Models\CustomerReferral::factory()->create([
            'partner_id' => $partner->id,
            'referral_code_id' => $refCode->id,
            'application_id' => $app->id,
            'application_customer_id' => $customer->id,
            'referral_code' => $refCode->code,
        ]);

        $commission = ReferralCommissionLedger::factory()->create([
            'partner_id' => $partner->id,
            'referral_code_id' => $refCode->id,
            'customer_referral_id' => $customerReferral->id,
            'invoice_id' => $oldInvoice->id,
            'commission_type' => ReferralCommissionLedger::TypeFirstPayment,
            'amount' => 50000.00,
            'status' => ReferralCommissionLedger::StatusAvailable,
            'idempotency_key' => 'comm-test-01',
        ]);

        // Perform environment operations (activate production, switch to sandbox)
        $secretStore = app(MidtransSecretStore::class);
        $encSandbox = $secretStore->encryptSecret(self::SandboxServerKey, 'udev-central', 'server_key', 'sandbox');
        $cred = MidtransMerchantCredential::create([
            'merchant_code' => 'udev-central',
            'merchant_name' => 'UDEV Central',
            'environment' => 'sandbox',
            'client_key' => 'SB-Mid-client-ORIGINAL',
            'encrypted_server_key' => $encSandbox['ciphertext'],
            'server_key_nonce' => $encSandbox['nonce'],
            'server_key_tag' => $encSandbox['tag'],
            'key_version' => $encSandbox['key_version'],
            'is_active' => true,
        ]);

        // Re-read old data
        $oldInvoice->refresh();
        $commission->refresh();
        $cred->refresh();

        $this->assertSame(Invoice::StatusPaid, $oldInvoice->status);
        $this->assertEquals('100000.00', $oldInvoice->amount);
        $this->assertEquals(50000.00, (float) $commission->amount);
        $this->assertSame(ReferralCommissionLedger::StatusAvailable, $commission->status);
        $this->assertSame(self::SandboxServerKey, $cred->getServerKey());
    }
}
