<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\ApplicationPaymentGateway;
use App\Models\CheckoutOutboxMessage;
use App\Models\DanaEnvironmentSetting;
use App\Models\DanaMerchantProvisioningAudit;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\DanaFeeService;
use App\Services\DanaGatewayMerchantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DanaMultiMerchantDualEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.dana_gateway', [
            'environment' => 'sandbox',
            'base_urls' => [
                'sandbox' => 'https://gateway-sandbox.test',
                'production' => 'https://gateway-prod.test',
            ],
            'timeout' => 8,
            'connect_timeout' => 3,
            'merchant_api_keys' => [
                'sandbox' => [
                    'udev-central' => 'key-central-sandbox',
                    'larissama' => 'key-larissama-sandbox',
                ],
                'production' => [
                    'udev-central' => 'key-central-prod',
                    'larissama' => 'key-larissama-prod',
                ],
            ],
            'merchants' => [
                'sandbox' => [
                    'udev-central' => ['name' => 'UDEV Central Sandbox', 'is_active' => true],
                    'larissama' => ['name' => 'Larissama Sandbox', 'is_active' => true],
                ],
                'production' => [
                    'udev-central' => ['name' => 'UDEV Central Prod', 'is_active' => true],
                    'larissama' => ['name' => 'Larissama Prod', 'is_active' => true],
                ],
            ],
        ]);

        config()->set('services.udev_billing.subscription_dana_sandbox_merchant_code', 'udev-central');
        config()->set('services.udev_billing.subscription_dana_production_merchant_code', 'udev-central');

        foreach (['sandbox', 'production'] as $env) {
            foreach (['qris', 'dana_wallet'] as $method) {
                foreach (['larissama', 'udev-central'] as $merchant) {
                    \App\Models\DanaPaymentFeeSetting::query()->create([
                        'gateway_merchant_code' => $merchant,
                        'application_id' => null,
                        'payment_method' => $method,
                        'environment' => $env,
                        'fee_type' => 'dana',
                        'fee_value' => 0.00,
                        'fee_unit' => 'rupiah',
                        'is_active' => true,
                    ]);
                }
            }
        }
    }

    public function test_udev_subscription_invoice_always_uses_udev_central_recipient(): void
    {
        $invoice = Invoice::factory()->create([
            'amount' => '100000.00',
            'currency' => 'IDR',
            'due_at' => now()->addDay(),
        ]);

        $this->fakeGateway('gateway-pay-central');

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
                'payment_method' => 'dana_wallet',
            ], ['Idempotency-Key' => 'test-idemp-001'])
            ->assertOk();

        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame('udev-central', $attempt->gateway_merchant_code);
        $this->assertSame('sandbox', $attempt->gateway_environment);
        $this->assertSame('udev_central', $attempt->metadata['billing_recipient'] ?? null);
    }

    public function test_customer_application_invoice_uses_mapped_merchant_and_prioritizes_production_when_ready(): void
    {
        $application = Application::factory()->create(['code' => 'APP-LARIS', 'name' => 'Larissama App']);
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id, 'price' => 50000]);

        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '50000.00',
            'currency' => 'IDR',
            'due_at' => now()->addDay(),
        ]);

        // Mapping Production
        ApplicationPaymentGateway::query()->create([
            'application_id' => $application->id,
            'provider' => 'dana',
            'environment' => 'production',
            'gateway_merchant_code' => 'larissama',
            'is_active' => true,
        ]);

        // Mapping Sandbox juga ada
        ApplicationPaymentGateway::query()->create([
            'application_id' => $application->id,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'larissama',
            'is_active' => true,
        ]);

        // Activate Production
        DanaEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'dana'],
            [
                'active_environment' => 'production',
                'is_production_ready' => true,
            ]
        );

        $this->fakeGateway('gateway-pay-prod', 'production');

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
                'payment_method' => 'qris',
            ], ['Idempotency-Key' => 'test-idemp-002'])
            ->assertOk();

        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame('larissama', $attempt->gateway_merchant_code);
        $this->assertSame('production', $attempt->gateway_environment);
        $this->assertSame('application_customer', $attempt->metadata['billing_recipient'] ?? null);
    }

    public function test_customer_application_with_dual_mapping_selects_sandbox_when_environment_is_sandbox(): void
    {
        $application = Application::factory()->create(['code' => 'APP-DUAL', 'name' => 'Dual App']);
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id, 'price' => 50000]);

        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '50000.00',
            'currency' => 'IDR',
            'due_at' => now()->addDay(),
        ]);

        ApplicationPaymentGateway::query()->create([
            'application_id' => $application->id,
            'provider' => 'dana',
            'environment' => 'production',
            'gateway_merchant_code' => 'larissama',
            'is_active' => true,
        ]);

        ApplicationPaymentGateway::query()->create([
            'application_id' => $application->id,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'larissama',
            'is_active' => true,
        ]);

        $this->fakeGateway('gateway-pay-sbx', 'sandbox');

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
                'payment_method' => 'qris',
            ], ['Idempotency-Key' => 'test-idemp-dual-sbx'])
            ->assertOk();

        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame('larissama', $attempt->gateway_merchant_code);
        $this->assertSame('sandbox', $attempt->gateway_environment);
        $this->assertSame('application_customer', $attempt->metadata['billing_recipient'] ?? null);
    }

    public function test_customer_application_invoice_uses_sandbox_when_production_mapping_not_present(): void
    {
        $application = Application::factory()->create(['code' => 'APP-DEV', 'name' => 'Dev App']);
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id, 'price' => 25000]);

        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '25000.00',
            'currency' => 'IDR',
            'due_at' => now()->addDay(),
        ]);

        // Hanya mapping Sandbox
        ApplicationPaymentGateway::query()->create([
            'application_id' => $application->id,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'larissama',
            'is_active' => true,
        ]);

        $this->fakeGateway('gateway-pay-sandbox', 'sandbox');

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
                'payment_method' => 'qris',
            ], ['Idempotency-Key' => 'test-idemp-003'])
            ->assertOk();

        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame('larissama', $attempt->gateway_merchant_code);
        $this->assertSame('sandbox', $attempt->gateway_environment);
    }

    public function test_production_operational_error_does_not_fallback_to_sandbox(): void
    {
        $application = Application::factory()->create(['code' => 'APP-PROD-FAIL', 'name' => 'Prod Fail App']);
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id]);

        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '75000.00',
            'currency' => 'IDR',
            'due_at' => now()->addDay(),
        ]);

        ApplicationPaymentGateway::query()->create([
            'application_id' => $application->id,
            'provider' => 'dana',
            'environment' => 'production',
            'gateway_merchant_code' => 'larissama',
            'is_active' => true,
        ]);

        // Activate Production
        DanaEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'dana'],
            [
                'active_environment' => 'production',
                'is_production_ready' => true,
            ]
        );

        // Simulasi Production Gateway 500 Internal Server Error
        Http::fake(function (HttpRequest $request) {
            return Http::response(['message' => 'DANA Production Down'], 500);
        });

        // Checkout disiapkan di Production, outbox dispatch gagal tetapi TIDAK dialihkan ke Sandbox
        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
                'payment_method' => 'dana_wallet',
            ], ['Idempotency-Key' => 'test-prod-err-001'])
            ->assertStatus(202); // Initiated/Accepted, waiting outbox retry

        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();
        // Pastikan environment tetap 'production', bukan 'sandbox'
        $this->assertSame('production', $attempt->gateway_environment);
        $this->assertSame('larissama', $attempt->gateway_merchant_code);

        $outbox = CheckoutOutboxMessage::query()->where('payment_attempt_id', $attempt->id)->sole();
        $this->assertNotSame('sandbox', $attempt->gateway_environment);
    }

    public function test_fee_service_supports_separate_environment_configuration(): void
    {
        $feeService = app(DanaFeeService::class);

        \App\Models\DanaPaymentFeeSetting::query()
            ->where('gateway_merchant_code', 'udev-central')
            ->where('payment_method', 'qris')
            ->update(['fee_value' => 0.70, 'fee_unit' => 'percent']);

        // Hitung fee di sandbox
        $feeSandbox = $feeService->calculateFee('udev-central', 'qris', 100000, 'sandbox');
        $this->assertSame('qris', $feeSandbox['payment_method']);
        $this->assertSame(700.0, (float) ($feeSandbox['dana_fee_amount'] ?? $feeSandbox['estimated_merchant_fee']));

        // Hitung fee di production
        $feeProd = $feeService->calculateFee('udev-central', 'qris', 100000, 'production');
        $this->assertSame('qris', $feeProd['payment_method']);
        $this->assertSame(700.0, (float) ($feeProd['dana_fee_amount'] ?? $feeProd['estimated_merchant_fee']));
    }

    public function test_cannot_deactivate_or_delete_udev_central_system_merchant(): void
    {
        $this->mockProvisioningClientFind('udev-central');

        // Coba toggle status udev-central
        $this->actingAdmin()
            ->patchJson('/api/v1/admin/dana-gateway-merchants/1/status', ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Merchant udev-central adalah merchant sistem pusat dan tidak dapat dinonaktifkan.');

        // Coba delete udev-central
        $this->actingAdmin()
            ->deleteJson('/api/v1/admin/dana-gateway-merchants/1')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Merchant udev-central adalah merchant sistem pusat dan tidak dapat dihapus.');
    }

    public function test_cannot_deactivate_or_delete_merchant_with_pending_transactions(): void
    {
        $this->mockProvisioningClientFind('larissama');

        $invoice = Invoice::factory()->create();
        PaymentAttempt::query()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'provider' => 'dana',
            'gateway_merchant_code' => 'larissama',
            'gateway_environment' => 'sandbox',
            'amount' => 10000,
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusPending,
            'idempotency_key' => 'idemp-pend-001',
        ]);

        // Coba nonaktifkan saat ada transaksi pending
        $this->actingAdmin()
            ->patchJson('/api/v1/admin/dana-gateway-merchants/2/status', ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Merchant larissama memiliki 1 transaksi pending dan tidak dapat dinonaktifkan.');

        // Coba hapus saat ada transaksi pending
        $this->actingAdmin()
            ->deleteJson('/api/v1/admin/dana-gateway-merchants/2')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Merchant larissama memiliki 1 transaksi pending dan tidak dapat dihapus.');
    }

    public function test_cannot_delete_merchant_with_transaction_history_and_recommends_deactivation(): void
    {
        $this->mockProvisioningClientFind('larissama');

        $invoice = Invoice::factory()->create();
        PaymentAttempt::query()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'provider' => 'dana',
            'gateway_merchant_code' => 'larissama',
            'gateway_environment' => 'sandbox',
            'amount' => 10000,
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusSucceeded,
            'idempotency_key' => 'idemp-hist-001',
        ]);

        $this->actingAdmin()
            ->deleteJson('/api/v1/admin/dana-gateway-merchants/2')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Merchant larissama memiliki riwayat 1 transaksi pembayaran dan tidak dapat dihapus. Silakan nonaktifkan merchant sebagai gantinya.');
    }

    public function test_cannot_deactivate_or_delete_merchant_with_active_application_mappings(): void
    {
        $this->mockProvisioningClientFind('larissama');

        $app = Application::factory()->create();
        ApplicationPaymentGateway::query()->create([
            'application_id' => $app->id,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'larissama',
            'is_active' => true,
        ]);

        $this->actingAdmin()
            ->patchJson('/api/v1/admin/dana-gateway-merchants/2/status', ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Merchant larissama masih terhubung ke 1 mapping aplikasi aktif. Nonaktifkan mapping terlebih dahulu.');

        $this->actingAdmin()
            ->deleteJson('/api/v1/admin/dana-gateway-merchants/2')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Merchant larissama masih digunakan oleh 1 mapping aplikasi aktif. Hapus atau nonaktifkan mapping terlebih dahulu.');
    }

    public function test_environment_activation_requires_explicit_confirmation_phrase(): void
    {
        // Tanpa frasa yang benar
        $this->actingAdmin()
            ->postJson('/api/v1/admin/dana-gateway-merchants/environment/activate-production', [
                'confirmation_phrase' => 'salah',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['confirmation_phrase']);

        // Mengambil status environment
        $this->actingAdmin()
            ->getJson('/api/v1/admin/dana-gateway-merchants/environment')
            ->assertOk()
            ->assertJsonPath('data.active_environment', 'sandbox');
    }

    public function test_gateway_network_failure_during_status_toggle_returns_error_and_does_not_claim_success(): void
    {
        Http::fake([
            '*/api/v1/internal/merchants/2/status' => Http::response(['message' => 'Internal Server Error'], 500),
            '*/api/v1/internal/merchants/2' => Http::response([
                'data' => $this->mockMerchantPayload('larissama', 2, 'active'),
            ]),
        ]);

        $this->actingAdmin()
            ->patchJson('/api/v1/admin/dana-gateway-merchants/2/status', ['is_active' => false])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Gateway provisioning merchant sedang mengalami gangguan.');

        // Pastikan tidak ada audit succeeded untuk tindakan ini
        $succeededAudit = DanaMerchantProvisioningAudit::query()
            ->where('gateway_merchant_id', 2)
            ->where('operation', 'dana_merchant.deactivated')
            ->where('status', 'succeeded')
            ->first();
        $this->assertNull($succeededAudit);

        // Pastikan audit tercatat dengan status failed
        $failedAudit = DanaMerchantProvisioningAudit::query()
            ->where('gateway_merchant_id', 2)
            ->where('operation', 'dana_merchant.deactivated')
            ->where('status', 'failed')
            ->first();
        $this->assertNotNull($failedAudit);
    }

    public function test_gateway_network_failure_during_delete_returns_error_and_does_not_claim_success(): void
    {
        Http::fake([
            '*/api/v1/internal/merchants/2' => function (HttpRequest $request) {
                if ($request->method() === 'DELETE') {
                    return Http::response(['message' => 'Gateway timeout'], 504);
                }

                return Http::response([
                    'data' => $this->mockMerchantPayload('larissama', 2, 'suspended'),
                ]);
            },
        ]);

        $this->actingAdmin()
            ->deleteJson('/api/v1/admin/dana-gateway-merchants/2')
            ->assertStatus(503)
            ->assertJsonPath('message', 'Gateway provisioning merchant sedang mengalami gangguan.');

        $succeededAudit = DanaMerchantProvisioningAudit::query()
            ->where('gateway_merchant_id', 2)
            ->where('operation', 'dana_merchant.deleted')
            ->where('status', 'succeeded')
            ->first();
        $this->assertNull($succeededAudit);

        $failedAudit = DanaMerchantProvisioningAudit::query()
            ->where('gateway_merchant_id', 2)
            ->where('operation', 'dana_merchant.deleted')
            ->where('status', 'failed')
            ->first();
        $this->assertNotNull($failedAudit);
    }

    public function test_gateway_successful_status_toggle_and_deletion_updates_audit_consistently(): void
    {
        Http::fake([
            '*/api/v1/internal/merchants/2/status' => Http::response([
                'data' => $this->mockMerchantPayload('larissama', 2, 'suspended'),
            ], 200),
            '*/api/v1/internal/merchants/2' => function (HttpRequest $request) {
                if ($request->method() === 'DELETE') {
                    return Http::response(['status' => 'success', 'message' => 'Merchant berhasil dihapus.'], 200);
                }

                return Http::response([
                    'data' => $this->mockMerchantPayload('larissama', 2, 'suspended'),
                ]);
            },
        ]);

        // 1. Sukses toggle status ke nonaktif
        $this->actingAdmin()
            ->patchJson('/api/v1/admin/dana-gateway-merchants/2/status', ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $statusAudit = DanaMerchantProvisioningAudit::query()
            ->where('gateway_merchant_id', 2)
            ->where('operation', 'dana_merchant.deactivated')
            ->where('status', 'succeeded')
            ->first();
        $this->assertNotNull($statusAudit);

        // 2. Sukses hapus merchant
        $this->actingAdmin()
            ->deleteJson('/api/v1/admin/dana-gateway-merchants/2')
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $deleteAudit = DanaMerchantProvisioningAudit::query()
            ->where('gateway_merchant_id', 2)
            ->where('operation', 'dana_merchant.deleted')
            ->where('status', 'succeeded')
            ->first();
        $this->assertNotNull($deleteAudit);
    }

    private function mockMerchantPayload(string $code = 'larissama', int $id = 2, string $status = 'active'): array
    {
        return [
            'merchant_id' => $id,
            'merchant_code' => $code,
            'merchant_name' => ucfirst($code),
            'environment' => 'sandbox',
            'merchant_status' => $status,
            'provisioning_status' => 'provisioned',
            'credential_status' => 'configured',
            'dana_configuration_status' => 'available',
            'provisioned_at' => now()->toIso8601String(),
        ];
    }

    private function actingAdmin(): self
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        return $this;
    }

    private function fakeGateway(string $paymentId = 'pay-001', string $env = 'sandbox'): void
    {
        Http::fake(function (HttpRequest $request) use ($paymentId, $env): mixed {
            if ($request->method() === 'GET') {
                return Http::response(['data' => []]);
            }

            return Http::response([
                'data' => [
                    'id' => $paymentId,
                    'merchant_order_id' => $request['merchant_order_id'],
                    'amount_minor' => $request['amount_minor'],
                    'currency' => 'IDR',
                    'status' => 'pending',
                    'checkout_url' => "https://checkout.{$env}.test/pay/{$paymentId}",
                    'expires_at' => now()->addMinutes(15)->toIso8601String(),
                    'metadata' => [
                        'udev_sandbox_payment_instruction' => [
                            'type' => 'qris_mpm',
                            'qr_content' => '00020101021226570011ID.DANA.WWW',
                        ],
                    ],
                ],
            ]);
        });
    }

    public function test_central_production_checkout_rejects_silent_fallback_when_merchant_not_ready(): void
    {
        DanaEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'dana'],
            [
                'active_environment' => 'production',
                'primary_merchant_code' => 'udev-central',
                'is_production_ready' => false,
            ]
        );

        // Set udev-central production merchant to not ready
        config()->set('services.dana_gateway.merchants.production.udev-central.is_active', false);

        $invoice = Invoice::factory()->create([
            'amount' => '1000.00',
            'currency' => 'IDR',
            'status' => Invoice::StatusPending,
            'due_at' => now()->addDay(),
        ]);

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
                'payment_method' => 'dana_wallet',
            ], ['Idempotency-Key' => 'test-central-prod-no-fallback'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['invoice'])
            ->assertJsonFragment(['Merchant DANA Production pusat belum siap digunakan. Transaksi live tidak dapat dialihkan ke Sandbox.']);
    }

    public function test_tenant_production_checkout_rejects_silent_fallback_when_prod_mapping_missing(): void
    {
        DanaEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'dana'],
            [
                'active_environment' => 'production',
                'is_production_ready' => true,
            ]
        );

        $application = Application::factory()->create(['code' => 'APP-ONLY-SBX']);
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id]);

        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '1000.00',
            'currency' => 'IDR',
            'status' => Invoice::StatusPending,
            'due_at' => now()->addDay(),
        ]);

        // Mapping hanya ada di sandbox
        ApplicationPaymentGateway::query()->create([
            'application_id' => $application->id,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'tenant-sbx-only',
            'is_active' => true,
        ]);

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
                'payment_method' => 'dana_wallet',
            ], ['Idempotency-Key' => 'test-tenant-prod-no-fallback'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['invoice'])
            ->assertJsonFragment(['Merchant DANA Production untuk aplikasi ini belum siap digunakan. Transaksi live tidak dapat dialihkan ke Sandbox.']);
    }

    public function test_checkout_dispatch_sends_environment_field_to_gateway(): void
    {
        $application = Application::factory()->create(['code' => 'APP-ENV-DISP']);
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id]);

        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '1000.00',
            'currency' => 'IDR',
            'status' => Invoice::StatusPending,
            'due_at' => now()->addDay(),
        ]);

        ApplicationPaymentGateway::query()->create([
            'application_id' => $application->id,
            'provider' => 'dana',
            'environment' => 'production',
            'gateway_merchant_code' => 'larissama',
            'is_active' => true,
        ]);

        // Activate Production
        DanaEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'dana'],
            [
                'active_environment' => 'production',
                'is_production_ready' => true,
            ]
        );

        $receivedEnvironment = null;
        Http::fake(function (HttpRequest $request) use (&$receivedEnvironment) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/api/v1/payments')) {
                $receivedEnvironment = $request['environment'] ?? null;

                return Http::response([
                    'data' => [
                        'id' => '01JPRODTEST0000000000000001',
                        'merchant_order_id' => $request['merchant_order_id'],
                        'amount_minor' => $request['amount_minor'],
                        'currency' => 'IDR',
                        'status' => 'pending',
                        'checkout_url' => 'https://checkout.production.test/pay/01JPRODTEST0000000000000001',
                        'expires_at' => now()->addMinutes(15)->toIso8601String(),
                        'metadata' => [
                            'udev_sandbox_payment_instruction' => [
                                'type' => 'redirect',
                                'message' => 'Lanjutkan pembayaran pada DANA.',
                            ],
                        ],
                    ],
                ]);
            }

            return Http::response(['data' => []]);
        });

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
                'payment_method' => 'dana_wallet',
            ], ['Idempotency-Key' => 'test-idemp-env-check'])
            ->assertOk();

        $this->assertSame('production', $receivedEnvironment);
    }

    public function test_resolver_fail_closed_when_production_base_url_is_empty(): void
    {
        config()->set('services.dana_gateway.base_urls.production', '');

        $resolver = app(DanaGatewayMerchantResolver::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DANA_GATEWAY_PRODUCTION_BASE_URL');

        $resolver->resolve('larissama', 'production');
    }

    public function test_resolver_production_uses_production_base_url_when_configured(): void
    {
        config()->set('services.dana_gateway.base_urls.production', 'https://dana.udumbara.my.id');

        $resolver = app(DanaGatewayMerchantResolver::class);
        $merchant = $resolver->resolve('larissama', 'production');

        $this->assertSame('https://dana.udumbara.my.id', $merchant->baseUrl);
        $this->assertSame('production', $merchant->environment);
        $this->assertSame('larissama', $merchant->code);
    }

    public function test_checkout_rejects_environment_manipulation_from_customer_request(): void
    {
        $invoice = Invoice::factory()->create([
            'amount' => '1000.00',
            'currency' => 'IDR',
            'status' => Invoice::StatusPending,
            'due_at' => now()->addDay(),
        ]);

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
                'payment_method' => 'dana_wallet',
                'environment' => 'production',
            ], ['Idempotency-Key' => 'test-prohibited-env'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['environment']);

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
                'payment_method' => 'dana_wallet',
                'gateway_environment' => 'sandbox',
            ], ['Idempotency-Key' => 'test-prohibited-gw-env'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['gateway_environment']);
    }

    private function mockProvisioningClientFind(string $code): void
    {
        Http::fake([
            '*/api/v1/internal/merchants/*' => Http::response([
                'data' => [
                    'merchant_id' => 1,
                    'merchant_code' => $code,
                    'merchant_name' => ucfirst($code),
                    'environment' => 'sandbox',
                    'merchant_status' => 'active',
                    'provisioning_status' => 'provisioned',
                    'credential_status' => 'configured',
                    'dana_configuration_status' => 'available',
                    'provisioned_at' => now()->toIso8601String(),
                ],
            ]),
        ]);
    }
}
