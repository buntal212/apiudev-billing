<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\ApplicationPaymentGateway;
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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
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

    public function test_index_fees_is_strictly_read_only_and_does_not_auto_seed_production(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        // Akses index Production saat database masih kosong
        $response = $this->getJson('/api/v1/admin/dana-gateway/fees?environment=production');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);

        // Verifikasi tidak ada write-on-read atau auto-seeding ke database Production
        $this->assertDatabaseEmpty('dana_payment_fee_settings');
    }

    public function test_index_fees_supports_all_environments_and_production_merchant_filter(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        // 1. Buat 1 aturan Sandbox global
        $sandboxFee = DanaPaymentFeeSetting::query()->create([
            'gateway_merchant_code' => null,
            'application_id' => null,
            'payment_method' => 'qris',
            'environment' => 'sandbox',
            'fee_type' => 'dana',
            'fee_value' => 0.70,
            'fee_unit' => 'percent',
            'is_active' => true,
        ]);

        // 2. Buat 1 aturan Production khusus merchant MRC-0002 (sesuai fakta Production)
        $prodFee = DanaPaymentFeeSetting::query()->create([
            'gateway_merchant_code' => 'MRC-0002',
            'application_id' => null,
            'payment_method' => 'qris',
            'environment' => 'production',
            'fee_type' => 'udev',
            'fee_value' => 500.00,
            'fee_unit' => 'rupiah',
            'is_active' => true,
        ]);

        // A. Filter Semua / Default (tanpa parameter environment): kedua aturan harus tampil
        $resAll = $this->getJson('/api/v1/admin/dana-gateway/fees');
        $resAll->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('meta.total', 2);
        $allIds = collect($resAll->json('data'))->pluck('id')->all();
        $this->assertContains($sandboxFee->id, $allIds);
        $this->assertContains($prodFee->id, $allIds);

        // B. Filter Semua via environment=all: kedua aturan harus tampil
        $resAllExplicit = $this->getJson('/api/v1/admin/dana-gateway/fees?environment=all');
        $resAllExplicit->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('meta.total', 2);

        // C. Filter Production: aturan MRC-0002 harus tampil, Sandbox tidak tampil
        $resProd = $this->getJson('/api/v1/admin/dana-gateway/fees?environment=production');
        $resProd->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $prodFee->id)
            ->assertJsonPath('data.0.gateway_merchant_code', 'MRC-0002')
            ->assertJsonPath('data.0.environment', 'production');

        // D. Filter Sandbox: aturan MRC-0002 TIDAK tampil, hanya Sandbox yang tampil
        $resSandbox = $this->getJson('/api/v1/admin/dana-gateway/fees?environment=sandbox');
        $resSandbox->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $sandboxFee->id)
            ->assertJsonPath('data.0.environment', 'sandbox');

        // E. Filter Production + Merchant MRC-0002: aturan ditemukan
        $resProdMerchant = $this->getJson('/api/v1/admin/dana-gateway/fees?environment=production&gateway_merchant_code=MRC-0002');
        $resProdMerchant->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.gateway_merchant_code', 'MRC-0002');
    }

    public function test_production_fees_must_be_created_explicitly_by_admin(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $payload = [
            'payment_method' => 'qris',
            'environment' => 'production',
            'fee_type' => 'dana',
            'fee_value' => 0.70,
            'fee_unit' => 'percent',
            'is_active' => true,
            'notes' => 'Tarif resmi MDR QRIS DANA Production',
        ];

        $response = $this->postJson('/api/v1/admin/dana-gateway/fees', $payload);

        $response->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.scope_key', 'global')
            ->assertJsonPath('data.environment', 'production')
            ->assertJsonPath('data.payment_method', 'qris')
            ->assertJsonPath('data.fee_type', 'dana')
            ->assertJsonPath('data.fee_value', '0.70');

        $this->assertDatabaseHas('dana_payment_fee_settings', [
            'scope_key' => 'global',
            'environment' => 'production',
            'payment_method' => 'qris',
            'fee_type' => 'dana',
            'fee_value' => 0.70,
        ]);
    }

    public function test_non_admin_cannot_manage_fees(): void
    {
        Sanctum::actingAs($this->regularUser, ['*']);

        $this->getJson('/api/v1/admin/dana-gateway/fees')->assertForbidden();
        $this->postJson('/api/v1/admin/dana-gateway/fees', [])->assertForbidden();
    }

    public function test_store_fee_supports_merchant_code_and_application_scope(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $app = Application::factory()->create(['name' => 'Larissama App']);

        $payload = [
            'gateway_merchant_code' => 'larissama',
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
            ->assertJsonPath('data.gateway_merchant_code', 'larissama')
            ->assertJsonPath('data.application_id', $app->id)
            ->assertJsonPath('data.scope_key', "app:{$app->id}:merchant:larissama");

        $this->assertDatabaseHas('dana_payment_fee_settings', [
            'gateway_merchant_code' => 'larissama',
            'application_id' => $app->id,
            'scope_key' => "app:{$app->id}:merchant:larissama",
            'is_active' => true,
        ]);
    }

    public function test_global_duplicate_is_strictly_rejected_at_db_and_api_level(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        // Buat aturan global pertama
        DanaPaymentFeeSetting::query()->create([
            'gateway_merchant_code' => null,
            'application_id' => null,
            'payment_method' => 'qris',
            'environment' => 'production',
            'fee_type' => 'dana',
            'fee_value' => 0.70,
            'fee_unit' => 'percent',
            'is_active' => true,
        ]);

        // Coba buat aturan global aktif kedua via API -> harus ditolak 422
        $response = $this->postJson('/api/v1/admin/dana-gateway/fees', [
            'gateway_merchant_code' => null,
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

        // Uji di layer database langsung: unique constraint pada scope_key='global'
        $this->expectException(QueryException::class);
        DanaPaymentFeeSetting::query()->create([
            'gateway_merchant_code' => null,
            'application_id' => null,
            'payment_method' => 'qris',
            'environment' => 'production',
            'fee_type' => 'dana',
            'fee_value' => 0.90,
            'fee_unit' => 'percent',
            'is_active' => true,
        ]);
    }

    public function test_multi_merchant_fee_resolution_and_isolation(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        // 1. Tarif Global UDEV Central: QRIS DANA = 0.70%, UDEV = Rp 0
        DanaPaymentFeeSetting::query()->create([
            'gateway_merchant_code' => null,
            'application_id' => null,
            'payment_method' => 'qris',
            'environment' => 'sandbox',
            'fee_type' => 'dana',
            'fee_value' => 0.70,
            'fee_unit' => 'percent',
            'is_active' => true,
        ]);

        // 2. Tarif Khusus Merchant Mandiri 'larissama': QRIS DANA = 0.50%, UDEV = Rp 1.500
        DanaPaymentFeeSetting::query()->create([
            'gateway_merchant_code' => 'larissama',
            'application_id' => null,
            'payment_method' => 'qris',
            'environment' => 'sandbox',
            'fee_type' => 'dana',
            'fee_value' => 0.50,
            'fee_unit' => 'percent',
            'is_active' => true,
        ]);
        DanaPaymentFeeSetting::query()->create([
            'gateway_merchant_code' => 'larissama',
            'application_id' => null,
            'payment_method' => 'qris',
            'environment' => 'sandbox',
            'fee_type' => 'udev',
            'fee_value' => 1500.00,
            'fee_unit' => 'rupiah',
            'is_active' => true,
        ]);

        $service = app(DanaFeeService::class);

        // Merchant Larissama harus menggunakan tarif 0.50% + Rp 1.500
        $larisCalc = $service->calculateFee('larissama', 'qris', 100000, 'sandbox');
        $this->assertSame(500.0, (float) $larisCalc['dana_fee_amount']);
        $this->assertSame(1500.0, (float) $larisCalc['udev_fee_amount']);
        $this->assertSame(102000.0, (float) $larisCalc['total_amount']);

        // Central Merchant harus menggunakan tarif global 0.70%
        $centralCalc = $service->calculateFee('udev-central', 'qris', 100000, 'sandbox');
        $this->assertSame(700.0, (float) $centralCalc['dana_fee_amount']);
        $this->assertSame(0.0, (float) $centralCalc['udev_fee_amount']);
        $this->assertSame(100700.0, (float) $centralCalc['total_amount']);
    }

    public function test_merchant_mandiri_without_fee_fails_closed_without_fallback_to_udev(): void
    {
        // Pasang tarif global UDEV Central
        DanaPaymentFeeSetting::query()->create([
            'gateway_merchant_code' => 'udev-central',
            'application_id' => null,
            'payment_method' => 'qris',
            'environment' => 'sandbox',
            'fee_type' => 'dana',
            'fee_value' => 0.70,
            'fee_unit' => 'percent',
            'is_active' => true,
        ]);

        $service = app(DanaFeeService::class);

        // Merchant mandiri 'larissama' BELUM memiliki tarif yang dikonfigurasi
        // Sistem HARUS fail-closed dan TIDAK BOLEH fallback ke udev-central
        $this->expectException(ValidationException::class);
        $service->calculateFee('larissama', 'qris', 100000, 'sandbox');
    }

    public function test_production_without_fee_fails_closed(): void
    {
        $service = app(DanaFeeService::class);

        // Production tanpa tarif untuk udev-central harus fail-closed
        $this->expectException(ValidationException::class);
        $service->calculateFee('udev-central', 'qris', 100000, 'production');
    }

    public function test_admin_can_update_toggle_and_delete_fee_setting(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $fee = DanaPaymentFeeSetting::query()->create([
            'gateway_merchant_code' => null,
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
            'gateway_merchant_code' => null,
            'application_id' => null,
            'payment_method' => 'qris',
            'environment' => 'production',
            'fee_type' => 'dana',
            'fee_value' => 0.70,
            'fee_unit' => 'percent',
            'is_active' => true,
        ]);

        DanaPaymentFeeSetting::query()->create([
            'gateway_merchant_code' => null,
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

        // Buat setting biaya khusus aplikasi & merchant larissama:
        // DANA QRIS: 0.70% (Rp 700)
        // UDEV QRIS: Rp 1.500
        DanaPaymentFeeSetting::query()->create([
            'gateway_merchant_code' => 'larissama',
            'application_id' => $application->id,
            'payment_method' => 'qris',
            'environment' => 'sandbox',
            'fee_type' => 'dana',
            'fee_value' => 0.70,
            'fee_unit' => 'percent',
            'is_active' => true,
        ]);

        DanaPaymentFeeSetting::query()->create([
            'gateway_merchant_code' => 'larissama',
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

        // Hak pemilik aplikasi (PaymentAllocation::amount) adalah sebesar tagihan pokok (50.000.00)
        $allocation = PaymentAllocation::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame('50000.00', (string) $allocation->amount);
        $this->assertSame(Invoice::StatusPaid, $invoice->fresh()->status);
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
