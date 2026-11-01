<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\Invoice;
use App\Models\MidtransMerchantCredential;
use App\Models\PaymentAttempt;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MidtransGatewayAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'is_admin' => true,
        ]);

        $this->regularUser = User::factory()->create([
            'is_admin' => false,
        ]);
    }

    public function test_unauthenticated_and_non_admin_cannot_access_midtrans_gateway(): void
    {
        $this->getJson('/api/v1/admin/midtrans-gateway/merchants')->assertStatus(401);
        $this->getJson('/api/v1/admin/midtrans-gateway/configuration')->assertStatus(401);

        Sanctum::actingAs($this->regularUser, ['admin:access']);
        $this->getJson('/api/v1/admin/midtrans-gateway/merchants')->assertStatus(403);
    }

    public function test_admin_can_list_merchants_and_defaults_are_available(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $response = $this->getJson('/api/v1/admin/midtrans-gateway/merchants');

        $response->assertOk()
            ->assertJsonFragment(['merchant_code' => 'udev-central'])
            ->assertJsonFragment(['merchant_code' => 'larissama']);
    }

    public function test_admin_can_create_update_and_delete_midtrans_merchant(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $createRes = $this->postJson('/api/v1/admin/midtrans-gateway/merchants', [
            'merchant_code' => 'toko-baru',
            'merchant_name' => 'Toko Baru Online',
            'midtrans_merchant_id' => 'G12345678',
            'client_key' => 'SB-Mid-client-abcdef',
            'is_active' => true,
        ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('data.merchant_code', 'toko-baru');

        // Update
        $updateRes = $this->putJson('/api/v1/admin/midtrans-gateway/merchants/toko-baru', [
            'merchant_name' => 'Toko Baru Update',
            'is_active' => true,
        ]);
        $updateRes->assertOk()
            ->assertJsonPath('data.merchant_name', 'Toko Baru Update');

        // Delete
        $deleteRes = $this->deleteJson('/api/v1/admin/midtrans-gateway/merchants/toko-baru');
        $deleteRes->assertOk();

        $this->assertDatabaseMissing('midtrans_merchant_credentials', ['merchant_code' => 'toko-baru']);
    }

    public function test_admin_can_save_encrypted_configuration_and_secrets_never_returned_in_plaintext(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $serverKey = 'SB-Mid-server-REALSECRETKEY12345';
        $signatureSecret = 'SuperSecretSignatureKey888';

        $saveRes = $this->putJson('/api/v1/admin/midtrans-gateway/configuration', [
            'merchant_code' => 'udev-central',
            'midtrans_merchant_id' => 'MIDTRANS-UDEV-001',
            'client_key' => 'SB-Mid-client-xyz987654321',
            'server_key' => $serverKey,
            'signature_secret_key' => $signatureSecret,
            'is_active' => true,
        ]);

        $saveRes->assertOk()
            ->assertJsonPath('data.has_server_key', true)
            ->assertJsonPath('data.has_signature_key', true);

        // Assert plaintext secrets are NOT in response
        $this->assertStringNotContainsString($serverKey, $saveRes->getContent());
        $this->assertStringNotContainsString($signatureSecret, $saveRes->getContent());

        // Assert GET configuration returns masked values, never plaintext
        $getRes = $this->getJson('/api/v1/admin/midtrans-gateway/configuration?merchant_code=udev-central');
        $getRes->assertOk()
            ->assertJsonPath('data.has_server_key', true)
            ->assertJsonPath('data.has_signature_key', true);

        $this->assertStringNotContainsString($serverKey, $getRes->getContent());
        $this->assertStringNotContainsString($signatureSecret, $getRes->getContent());
        $this->assertStringContainsString('****', $getRes->json('data.masked_server_key'));
    }

    public function test_admin_can_test_connection_probe(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        // First configure server key
        $this->putJson('/api/v1/admin/midtrans-gateway/configuration', [
            'merchant_code' => 'udev-central',
            'server_key' => 'SB-Mid-server-testprobe123',
            'is_active' => true,
        ]);

        // Mock Midtrans Core API probe call
        Http::fake([
            'https://api.sandbox.midtrans.com/v2/*' => Http::response([
                'status_code' => '404',
                'status_message' => 'Transaction doesn\'t exist.',
            ], 404),
        ]);

        $probeRes = $this->postJson('/api/v1/admin/midtrans-gateway/test-connection', [
            'merchant_code' => 'udev-central',
        ]);

        $probeRes->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('is_connected', true);

        // Check credential updated
        $cred = MidtransMerchantCredential::where('merchant_code', 'udev-central')->first();
        $this->assertEquals('success', $cred->last_test_status);
    }

    public function test_admin_can_create_sandbox_charge_and_query_status(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $this->putJson('/api/v1/admin/midtrans-gateway/configuration', [
            'merchant_code' => 'udev-central',
            'server_key' => 'SB-Mid-server-testprobe123',
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'status_message' => 'Success, QRIS transaction is created',
                'transaction_id' => 'midtrans-trans-111222',
                'order_id' => 'ORDER-TEST-001',
                'gross_amount' => '15000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'pending',
                'qr_string' => '00020101021226680016ID.CO.SHOPEE.WWW...',
                'actions' => [
                    ['name' => 'generate-qr-code', 'url' => 'https://api.sandbox.midtrans.com/v2/qris/111222/qr-code'],
                ],
            ], 201),
            'https://api.sandbox.midtrans.com/v2/ORDER-TEST-001/status' => Http::response([
                'status_code' => '200',
                'status_message' => 'Success, transaction found',
                'transaction_id' => 'midtrans-trans-111222',
                'order_id' => 'ORDER-TEST-001',
                'gross_amount' => '15000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'settlement',
            ], 200),
        ]);

        $chargeRes = $this->postJson('/api/v1/admin/midtrans-gateway/sandbox-payment', [
            'merchant_code' => 'udev-central',
            'order_id' => 'ORDER-TEST-001',
            'amount' => 15000,
            'payment_type' => 'qris',
        ]);

        $chargeRes->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.order_id', 'ORDER-TEST-001')
            ->assertJsonPath('data.qr_string', '00020101021226680016ID.CO.SHOPEE.WWW...');

        $statusRes = $this->getJson('/api/v1/admin/midtrans-gateway/sandbox-payment/ORDER-TEST-001/status?merchant_code=udev-central');
        $statusRes->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.transaction_status', 'settlement');
    }

    public function test_admin_can_simulate_bca_virtual_account_with_exact_payload(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $this->putJson('/api/v1/admin/midtrans-gateway/configuration', [
            'merchant_code' => 'udev-central',
            'server_key' => 'SB-Mid-server-testbca',
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'status_message' => 'Success, BCA transaction created',
                'transaction_id' => 'midtrans-trans-bca',
                'order_id' => 'ORDER-BCA-001',
                'gross_amount' => '20000.00',
                'payment_type' => 'bank_transfer',
                'transaction_status' => 'pending',
                'va_numbers' => [
                    ['bank' => 'bca', 'va_number' => '9880123456789012'],
                ],
            ], 201),
        ]);

        $chargeRes = $this->postJson('/api/v1/admin/midtrans-gateway/sandbox-payment', [
            'merchant_code' => 'udev-central',
            'order_id' => 'ORDER-BCA-001',
            'amount' => 20000,
            'payment_type' => 'bank_transfer',
            'bank' => 'bca',
        ]);

        $chargeRes->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.payment_type', 'bank_transfer')
            ->assertJsonPath('data.bank', 'bca')
            ->assertJsonPath('data.va_numbers.0.bank', 'bca')
            ->assertJsonPath('data.va_numbers.0.va_number', '9880123456789012');

        Http::assertSent(function ($request) {
            return $request['payment_type'] === 'bank_transfer'
                && ($request['bank_transfer']['bank'] ?? '') === 'bca';
        });
    }

    public function test_admin_can_simulate_bni_virtual_account_with_exact_payload(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $this->putJson('/api/v1/admin/midtrans-gateway/configuration', [
            'merchant_code' => 'udev-central',
            'server_key' => 'SB-Mid-server-testbni',
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'status_message' => 'Success, BNI transaction created',
                'transaction_id' => 'midtrans-trans-bni',
                'order_id' => 'ORDER-BNI-001',
                'gross_amount' => '25000.00',
                'payment_type' => 'bank_transfer',
                'transaction_status' => 'pending',
                'va_numbers' => [
                    ['bank' => 'bni', 'va_number' => '9880987654321098'],
                ],
            ], 201),
        ]);

        $chargeRes = $this->postJson('/api/v1/admin/midtrans-gateway/sandbox-payment', [
            'merchant_code' => 'udev-central',
            'order_id' => 'ORDER-BNI-001',
            'amount' => 25000,
            'payment_type' => 'bank_transfer',
            'bank' => 'bni',
        ]);

        $chargeRes->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.bank', 'bni')
            ->assertJsonPath('data.va_numbers.0.bank', 'bni')
            ->assertJsonPath('data.va_numbers.0.va_number', '9880987654321098');

        Http::assertSent(function ($request) {
            return $request['payment_type'] === 'bank_transfer'
                && ($request['bank_transfer']['bank'] ?? '') === 'bni';
        });
    }

    public function test_admin_can_simulate_bri_virtual_account_with_exact_payload(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $this->putJson('/api/v1/admin/midtrans-gateway/configuration', [
            'merchant_code' => 'udev-central',
            'server_key' => 'SB-Mid-server-testbri',
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'status_message' => 'Success, BRI transaction created',
                'transaction_id' => 'midtrans-trans-bri',
                'order_id' => 'ORDER-BRI-001',
                'gross_amount' => '30000.00',
                'payment_type' => 'bank_transfer',
                'transaction_status' => 'pending',
                'va_numbers' => [
                    ['bank' => 'bri', 'va_number' => '9880112233445566'],
                ],
            ], 201),
        ]);

        $chargeRes = $this->postJson('/api/v1/admin/midtrans-gateway/sandbox-payment', [
            'merchant_code' => 'udev-central',
            'order_id' => 'ORDER-BRI-001',
            'amount' => 30000,
            'payment_type' => 'bank_transfer',
            'bank' => 'bri',
        ]);

        $chargeRes->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.bank', 'bri')
            ->assertJsonPath('data.va_numbers.0.bank', 'bri')
            ->assertJsonPath('data.va_numbers.0.va_number', '9880112233445566');

        Http::assertSent(function ($request) {
            return $request['payment_type'] === 'bank_transfer'
                && ($request['bank_transfer']['bank'] ?? '') === 'bri';
        });
    }

    public function test_admin_can_simulate_permata_virtual_account_with_exact_payload(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $this->putJson('/api/v1/admin/midtrans-gateway/configuration', [
            'merchant_code' => 'udev-central',
            'server_key' => 'SB-Mid-server-testpermata',
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'status_message' => 'Success, Permata transaction created',
                'transaction_id' => 'midtrans-trans-permata',
                'order_id' => 'ORDER-PERMATA-001',
                'gross_amount' => '35000.00',
                'payment_type' => 'bank_transfer',
                'transaction_status' => 'pending',
                'permata_va_number' => '8562000087654321',
            ], 201),
        ]);

        $chargeRes = $this->postJson('/api/v1/admin/midtrans-gateway/sandbox-payment', [
            'merchant_code' => 'udev-central',
            'order_id' => 'ORDER-PERMATA-001',
            'amount' => 35000,
            'payment_type' => 'bank_transfer',
            'bank' => 'permata',
        ]);

        $chargeRes->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.bank', 'permata')
            ->assertJsonPath('data.permata_va_number', '8562000087654321')
            ->assertJsonPath('data.va_numbers.0.bank', 'permata')
            ->assertJsonPath('data.va_numbers.0.va_number', '8562000087654321');

        Http::assertSent(function ($request) {
            return $request['payment_type'] === 'bank_transfer'
                && ($request['bank_transfer']['bank'] ?? '') === 'permata';
        });
    }

    public function test_admin_can_simulate_mandiri_bill_payment_with_exact_payload(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $this->putJson('/api/v1/admin/midtrans-gateway/configuration', [
            'merchant_code' => 'udev-central',
            'server_key' => 'SB-Mid-server-testmandiri',
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'status_message' => 'Success, Mandiri Bill transaction created',
                'transaction_id' => 'midtrans-trans-mandiri',
                'order_id' => 'ORDER-MANDIRI-001',
                'gross_amount' => '40000.00',
                'payment_type' => 'echannel',
                'transaction_status' => 'pending',
                'biller_code' => '70012',
                'bill_key' => '990000000123',
            ], 201),
        ]);

        $chargeRes = $this->postJson('/api/v1/admin/midtrans-gateway/sandbox-payment', [
            'merchant_code' => 'udev-central',
            'order_id' => 'ORDER-MANDIRI-001',
            'amount' => 40000,
            'payment_type' => 'bank_transfer',
            'bank' => 'mandiri',
        ]);

        $chargeRes->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.payment_type', 'echannel')
            ->assertJsonPath('data.bank', 'mandiri')
            ->assertJsonPath('data.biller_code', '70012')
            ->assertJsonPath('data.bill_key', '990000000123')
            ->assertJsonPath('data.va_numbers.0.bank', 'mandiri')
            ->assertJsonPath('data.va_numbers.0.va_number', '990000000123');

        Http::assertSent(function ($request) {
            return $request['payment_type'] === 'echannel'
                && isset($request['echannel']['bill_info1']);
        });
    }

    public function test_admin_can_simulate_gopay_with_exact_payload(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $this->putJson('/api/v1/admin/midtrans-gateway/configuration', [
            'merchant_code' => 'udev-central',
            'server_key' => 'SB-Mid-server-testgopay',
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'status_message' => 'Success, GoPay transaction created',
                'transaction_id' => 'midtrans-trans-gopay',
                'order_id' => 'ORDER-GOPAY-001',
                'gross_amount' => '12000.00',
                'payment_type' => 'gopay',
                'transaction_status' => 'pending',
                'actions' => [
                    ['name' => 'deeplink-redirect', 'url' => 'https://simulator.sandbox.midtrans.com/gopay/partner/app/payment-pin?id=123'],
                ],
            ], 201),
        ]);

        $chargeRes = $this->postJson('/api/v1/admin/midtrans-gateway/sandbox-payment', [
            'merchant_code' => 'udev-central',
            'order_id' => 'ORDER-GOPAY-001',
            'amount' => 12000,
            'payment_type' => 'gopay',
        ]);

        $chargeRes->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.payment_type', 'gopay')
            ->assertJsonPath('data.checkout_url', 'https://simulator.sandbox.midtrans.com/gopay/partner/app/payment-pin?id=123');

        Http::assertSent(function ($request) {
            return $request['payment_type'] === 'gopay'
                && ($request['gopay']['enable_callback'] ?? false) === true;
        });
    }

    public function test_sandbox_payment_rejects_unsupported_bank(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $chargeRes = $this->postJson('/api/v1/admin/midtrans-gateway/sandbox-payment', [
            'merchant_code' => 'udev-central',
            'order_id' => 'ORDER-INVALID-001',
            'amount' => 10000,
            'payment_type' => 'bank_transfer',
            'bank' => 'cimb_unsupported',
        ]);

        $chargeRes->assertStatus(422)
            ->assertJsonValidationErrors(['bank']);
    }

    public function test_admin_can_view_audits_and_dashboard_summary(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $auditsRes = $this->getJson('/api/v1/admin/midtrans-gateway/audits');
        $auditsRes->assertOk();

        $summaryRes = $this->getJson('/api/v1/admin/midtrans-gateway/dashboard-summary');
        $summaryRes->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'total_merchants',
                    'active_merchants',
                    'configured_merchants',
                    'total_mappings',
                    'active_mappings',
                    'total_billing_transactions',
                    'successful_billing_transactions',
                    'total_sandbox_tests',
                    'api_status',
                ],
            ]);
    }

    public function test_application_payment_gateway_mapping_for_midtrans(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $app = Application::create([
            'name' => 'Aplikasi Kasir UDEV',
            'code' => 'kasir-udev',
        ]);

        // List merchants for provider=midtrans
        $merchantsRes = $this->getJson('/api/v1/admin/payment-gateways/merchants?provider=midtrans&environment=sandbox');
        $merchantsRes->assertOk()
            ->assertJsonFragment(['code' => 'udev-central']);

        // Create mapping
        $mappingRes = $this->postJson('/api/v1/admin/payment-gateways', [
            'application_id' => $app->id,
            'provider' => 'midtrans',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'udev-central',
            'is_active' => true,
        ]);

        $mappingRes->assertStatus(201)
            ->assertJsonPath('data.provider', 'midtrans')
            ->assertJsonPath('data.gateway_merchant_code', 'udev-central');

        $gatewayId = $mappingRes->json('data.id');

        // Update mapping
        $updateRes = $this->putJson("/api/v1/admin/payment-gateways/{$gatewayId}", [
            'is_active' => false,
        ]);
        $updateRes->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_billing_gateway_transactions_filter_by_midtrans_provider(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $app = Application::create(['name' => 'App Test', 'code' => 'app-test']);
        $customer = ApplicationCustomer::create([
            'application_id' => $app->id,
            'external_customer_id' => 'cust-01',
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
        ]);
        $plan = SubscriptionPlan::create([
            'application_id' => $app->id,
            'code' => 'plan-basic',
            'name' => 'Basic Plan',
            'duration_months' => 1,
            'duration_days' => 30,
            'price' => 50000,
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-2026-TEST-001',
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => 50000,
            'currency' => 'IDR',
            'status' => 'pending',
            'idempotency_key' => 'idemp-inv-001',
            'duration_months' => 1,
            'duration_days' => 30,
            'period_count' => 1,
            'issued_at' => now(),
            'due_at' => now()->addDays(7),
        ]);

        $attempt = PaymentAttempt::create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'provider' => 'midtrans',
            'merchant_reference' => 'INV-2026-TEST-001-A1',
            'gateway_merchant_code' => 'udev-central',
            'gateway_environment' => 'sandbox',
            'amount' => 50000,
            'currency' => 'IDR',
            'status' => 'pending',
            'idempotency_key' => 'attempt-idemp-001',
            'requested_at' => now(),
        ]);

        $listRes = $this->getJson('/api/v1/admin/payment-gateway/billing-transactions?provider=midtrans');
        $listRes->assertOk()
            ->assertJsonPath('data.0.references.merchant', 'INV-2026-TEST-001-A1')
            ->assertJsonPath('data.0.gateway.provider', 'midtrans');

        $showRes = $this->getJson("/api/v1/admin/payment-gateway/billing-transactions/{$attempt->id}");
        $showRes->assertOk()
            ->assertJsonPath('data.gateway.provider', 'midtrans');
    }
}
