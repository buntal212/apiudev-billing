<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\ApplicationPaymentGateway;
use App\Models\DanaMerchantProvisioningAudit;
use App\Models\DanaPaymentFeeSetting;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\PaymentAttempt;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\DanaFeeService;
use App\Services\InvoiceSettlementService;
use App\Services\VerifiedGatewayPayment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class DanaPaymentFeeSettingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.dana_gateway', [
            'environment' => 'sandbox',
            'base_urls' => [
                'sandbox' => 'https://gateway-sandbox.test',
                'production' => 'https://dana.udumbara.my.id',
            ],
            'timeout' => 8,
            'connect_timeout' => 3,
            'merchant_api_keys' => [
                'sandbox' => [
                    'udev-central' => 'key-central-sandbox',
                    'larissama' => 'key-laris-sandbox',
                ],
                'production' => [
                    'udev-central' => 'key-central-prod',
                    'larissama' => 'key-laris-prod',
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

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->regularUser = User::factory()->create(['is_admin' => false]);
    }

    public function test_index_fees_auto_seeds_official_defaults_and_returns_paginated_list(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $response = $this->getJson('/api/v1/admin/dana-gateway/fees?environment=production');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'status',
                'data' => [
                    '*' => [
                        'id',
                        'application_id',
                        'payment_method',
                        'environment',
                        'fee_type',
                        'fee_value',
                        'fee_unit',
                        'is_active',
                    ],
                ],
                'meta' => ['current_page', 'total', 'per_page', 'has_more'],
            ]);

        // Verifikasi tarif resmi Production terisi (QRIS 0.70%, DANA Wallet 1.50%, VA Rp 4.000)
        $this->assertDatabaseHas('dana_payment_fee_settings', [
            'environment' => 'production',
            'payment_method' => 'qris',
            'fee_type' => 'dana',
            'fee_value' => '0.70',
            'fee_unit' => 'percent',
            'application_id' => null,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('dana_payment_fee_settings', [
            'environment' => 'production',
            'payment_method' => 'dana_wallet',
            'fee_type' => 'dana',
            'fee_value' => '1.50',
            'fee_unit' => 'percent',
            'application_id' => null,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('dana_payment_fee_settings', [
            'environment' => 'production',
            'payment_method' => 'va_bri',
            'fee_type' => 'dana',
            'fee_value' => '4000.00',
            'fee_unit' => 'rupiah',
            'application_id' => null,
            'is_active' => true,
        ]);
    }

    public function test_non_admin_cannot_manage_fees(): void
    {
        Sanctum::actingAs($this->regularUser, ['*']);

        $this->getJson('/api/v1/admin/dana-gateway/fees')->assertForbidden();
        $this->postJson('/api/v1/admin/dana-gateway/fees', [])->assertForbidden();
    }

    public function test_store_fee_validates_and_creates_new_setting_with_audit_log(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $app = Application::factory()->create(['name' => 'Larissama App']);

        $payload = [
            'application_id' => $app->id,
            'payment_method' => 'qris',
            'environment' => 'production',
            'fee_type' => 'udev',
            'fee_value' => 1500.00,
            'fee_unit' => 'rupiah',
            'is_active' => true,
            'notes' => 'Biaya UDEV khusus Larissama',
        ];

        $response = $this->postJson('/api/v1/admin/dana-gateway/fees', $payload);

        $response->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.application_id', $app->id)
            ->assertJsonPath('data.payment_method', 'qris')
            ->assertJsonPath('data.fee_type', 'udev')
            ->assertJsonPath('data.fee_value', '1500.00')
            ->assertJsonPath('data.fee_unit', 'rupiah');

        $this->assertDatabaseHas('dana_payment_fee_settings', [
            'application_id' => $app->id,
            'payment_method' => 'qris',
            'environment' => 'production',
            'fee_type' => 'udev',
            'fee_value' => 1500.00,
            'is_active' => true,
        ]);

        // Verifikasi audit log tercatat
        $this->assertDatabaseHas('dana_merchant_provisioning_audits', [
            'operation' => 'dana_fee_setting.created',
            'status' => 'succeeded',
        ]);
    }

    public function test_duplicate_active_fee_setting_is_strictly_rejected(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        DanaPaymentFeeSetting::query()->create([
            'application_id' => null,
            'payment_method' => 'qris',
            'environment' => 'production',
            'fee_type' => 'dana',
            'fee_value' => 0.70,
            'fee_unit' => 'percent',
            'is_active' => true,
        ]);

        // Coba buat tarif aktif kedua untuk kombinasi yang sama -> harus ditolak 422
        $response = $this->postJson('/api/v1/admin/dana-gateway/fees', [
            'application_id' => null,
            'payment_method' => 'qris',
            'environment' => 'production',
            'fee_type' => 'dana',
            'fee_value' => 0.80,
            'fee_unit' => 'percent',
            'is_active' => true,
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['payment_method']);
    }

    public function test_admin_can_update_toggle_and_delete_fee_setting(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $fee = DanaPaymentFeeSetting::query()->create([
            'application_id' => null,
            'payment_method' => 'dana_wallet',
            'environment' => 'sandbox',
            'fee_type' => 'udev',
            'fee_value' => 500.00,
            'fee_unit' => 'rupiah',
            'is_active' => true,
            'notes' => 'Awal',
        ]);

        // 1. Update
        $this->putJson("/api/v1/admin/dana-gateway/fees/{$fee->id}", [
            'fee_value' => 1000.00,
            'notes' => 'Diperbarui',
        ])->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.fee_value', '1000.00')
            ->assertJsonPath('data.notes', 'Diperbarui');

        // 2. Toggle status
        $this->patchJson("/api/v1/admin/dana-gateway/fees/{$fee->id}/status", [
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($fee->fresh()->is_active);

        // 3. Delete
        $this->deleteJson("/api/v1/admin/dana-gateway/fees/{$fee->id}")
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseMissing('dana_payment_fee_settings', ['id' => $fee->id]);
    }

    public function test_calculate_fee_preview_endpoint(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        // Set fee: QRIS DANA = 0.70%, UDEV = Rp 1.000
        DanaPaymentFeeSetting::query()->create([
            'application_id' => null,
            'payment_method' => 'qris',
            'environment' => 'production',
            'fee_type' => 'dana',
            'fee_value' => 0.70,
            'fee_unit' => 'percent',
            'is_active' => true,
        ]);

        DanaPaymentFeeSetting::query()->create([
            'application_id' => null,
            'payment_method' => 'qris',
            'environment' => 'production',
            'fee_type' => 'udev',
            'fee_value' => 1000.00,
            'fee_unit' => 'rupiah',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/admin/dana-gateway/calculate-fee', [
            'payment_method' => 'qris',
            'amount' => 100000,
            'environment' => 'production',
        ]);

        // Tagihan pokok = 100.000
        // Biaya DANA = 0.7% x 100.000 = 700
        // Biaya UDEV = 1.000
        // Total Bayar = 101.700
        // Hak Pemilik Aplikasi = 100.000 (utuh)
        $response->assertOk()
            ->assertJsonPath('data.base_amount', 100000)
            ->assertJsonPath('data.dana_fee_amount', 700)
            ->assertJsonPath('data.dana_fee_unit', 'percent')
            ->assertJsonPath('data.udev_fee_amount', 1000)
            ->assertJsonPath('data.udev_fee_unit', 'rupiah')
            ->assertJsonPath('data.total_fee', 1700)
            ->assertJsonPath('data.total_amount', 101700)
            ->assertJsonPath('data.estimated_net_settlement', 100000);
    }

    public function test_checkout_charges_both_fees_to_customer_and_stores_snapshot(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $application = Application::factory()->create(['name' => 'Larissama App']);
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id, 'price' => 100000]);

        ApplicationPaymentGateway::query()->create([
            'application_id' => $application->id,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'larissama',
            'is_active' => true,
        ]);

        // Buat setting biaya khusus aplikasi:
        // DANA QRIS: 0.70% (Rp 700)
        // UDEV QRIS: Rp 1.500
        DanaPaymentFeeSetting::query()->create([
            'application_id' => $application->id,
            'payment_method' => 'qris',
            'environment' => 'sandbox',
            'fee_type' => 'dana',
            'fee_value' => 0.70,
            'fee_unit' => 'percent',
            'is_active' => true,
        ]);

        DanaPaymentFeeSetting::query()->create([
            'application_id' => $application->id,
            'payment_method' => 'qris',
            'environment' => 'sandbox',
            'fee_type' => 'udev',
            'fee_value' => 1500.00,
            'fee_unit' => 'rupiah',
            'is_active' => true,
        ]);

        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '100000.00',
            'currency' => 'IDR',
            'due_at' => now()->addDay(),
        ]);

        $this->fakeCheckoutGateway('pay-fee-test-001');

        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'qris',
        ], ['Idempotency-Key' => 'test-idemp-fee-01'])->assertAccepted();

        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();

        // Total yang dibebankan ke pelanggan adalah 100.000 + 700 + 1.500 = 102.200
        $this->assertSame('102200.00', (string) $attempt->amount);

        // Snapshot tersimpan pada metadata
        $snapshot = $attempt->metadata['fee_breakdown'];
        $this->assertNotNull($snapshot);
        $this->assertSame(700.0, (float) $snapshot['dana_fee']);
        $this->assertSame('percent', $snapshot['dana_fee_unit']);
        $this->assertSame(1500.0, (float) $snapshot['udev_fee']);
        $this->assertSame('rupiah', $snapshot['udev_fee_unit']);
        $this->assertSame(2200.0, (float) $snapshot['total_fee']);
        $this->assertSame(102200.0, (float) $snapshot['total_amount']);

        // Hak pemilik aplikasi sebesar tagihan pokok (100.000)
        $this->assertSame(100000.0, (float) $snapshot['estimated_net_settlement']);
    }

    public function test_application_owner_receives_exact_base_invoice_amount_upon_settlement(): void
    {
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id, 'price' => 50000]);

        ApplicationPaymentGateway::query()->create([
            'application_id' => $application->id,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'udev-central',
            'is_active' => true,
        ]);

        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '50000.00',
            'currency' => 'IDR',
            'status' => Invoice::StatusPending,
            'due_at' => now()->addDay(),
        ]);

        $attempt = PaymentAttempt::query()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'provider' => 'dana',
            'gateway_payment_id' => 'pay-settle-001',
            'merchant_reference' => 'ord-001',
            'gateway_merchant_code' => 'udev-central',
            'gateway_environment' => 'sandbox',
            'amount' => '51350.00', // 50.000 + 350 (0.7% DANA) + 1.000 (UDEV)
            'status' => PaymentAttempt::StatusSucceeded,
            'currency' => 'IDR',
            'idempotency_key' => 'checkout:'.$invoice->id.':test-settle-fee',
            'requested_at' => now(),
            'completed_at' => now(),
            'metadata' => [
                'payment_method' => 'qris',
                'fee_breakdown' => [
                    'fee_amount' => 1350.00,
                    'dana_fee' => 350.00,
                    'udev_fee' => 1000.00,
                    'total_fee' => 1350.00,
                ],
            ],
        ]);

        $settlementService = app(InvoiceSettlementService::class);
        $verification = new VerifiedGatewayPayment(
            provider: 'dana',
            gatewayPaymentId: 'pay-settle-001',
            merchantOrderId: 'ord-001',
            amountMinor: 51350,
            currency: 'IDR',
            merchantCode: 'udev-central',
            environment: 'sandbox',
            paidAt: CarbonImmutable::now(),
        );

        $result = $settlementService->settle($attempt, $verification, 'settle-idemp-001');

        // Hak pemilik aplikasi (PaymentAllocation::amount) adalah sebesar tagihan pokok (50.000.00), bukan nilai surcharge
        $allocation = PaymentAllocation::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame('50000.00', (string) $allocation->amount);
        $this->assertSame(Invoice::StatusPaid, $invoice->fresh()->status);
    }

    public function test_app_specific_fee_overrides_global_default(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $appSpecial = Application::factory()->create(['name' => 'Special App']);
        $appNormal = Application::factory()->create(['name' => 'Normal App']);

        // Global default UDEV fee = Rp 500
        DanaPaymentFeeSetting::query()->create([
            'application_id' => null,
            'payment_method' => 'qris',
            'environment' => 'sandbox',
            'fee_type' => 'udev',
            'fee_value' => 500.00,
            'fee_unit' => 'rupiah',
            'is_active' => true,
        ]);

        // Special App override UDEV fee = Rp 2.000
        DanaPaymentFeeSetting::query()->create([
            'application_id' => $appSpecial->id,
            'payment_method' => 'qris',
            'environment' => 'sandbox',
            'fee_type' => 'udev',
            'fee_value' => 2000.00,
            'fee_unit' => 'rupiah',
            'is_active' => true,
        ]);

        $service = app(DanaFeeService::class);

        // Special App calculation uses Rp 2.000
        $specialCalc = $service->calculateFee('udev-central', 'qris', 100000, 'sandbox', $appSpecial->id);
        $this->assertSame(2000.0, (float) $specialCalc['udev_fee_amount']);

        // Normal App calculation uses global default Rp 500
        $normalCalc = $service->calculateFee('udev-central', 'qris', 100000, 'sandbox', $appNormal->id);
        $this->assertSame(500.0, (float) $normalCalc['udev_fee_amount']);
    }

    private function fakeCheckoutGateway(string $paymentId = 'pay-001'): void
    {
        Http::fake([
            '*/api/v1/payments' => Http::response([
                'data' => [
                    'id' => $paymentId,
                    'merchant_order_id' => 'ord-001',
                    'amount_minor' => 10220000,
                    'currency' => 'IDR',
                    'status' => 'pending',
                    'checkout_url' => "https://checkout.sandbox.test/pay/{$paymentId}",
                    'expires_at' => now()->addMinutes(15)->toIso8601String(),
                    'metadata' => [
                        'udev_sandbox_payment_instruction' => [
                            'type' => 'qris_mpm',
                            'qr_content' => '00020101021226570011ID.DANA.WWW',
                        ],
                    ],
                ],
            ]),
            '*/api/v1/internal/merchants*' => Http::response([
                'data' => [
                    'merchant_id' => 1,
                    'merchant_code' => 'larissama',
                    'merchant_name' => 'Larissama',
                    'environment' => 'sandbox',
                    'merchant_status' => 'active',
                    'provisioning_status' => 'provisioned',
                    'credential_status' => 'configured',
                    'dana_configuration_status' => 'available',
                    'ready' => true,
                ],
            ]),
        ]);
    }
}
