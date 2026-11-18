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
                'production' => 'https://gateway-prod.test',
            ],
            'timeout' => 8,
            'connect_timeout' => 3,
            'merchant_api_keys' => [
                'sandbox' => [
                    'udev-central' => 'key-central-sandbox',
                    'MRC-0002' => 'key-mrc0002-sandbox',
                    'larissama' => 'key-laris-sandbox',
                ],
                'production' => [
                    'udev-central' => 'key-central-prod',
                    'MRC-0002' => 'key-mrc0002-prod',
                    'larissama' => 'key-laris-prod',
                ],
            ],
            'merchants' => [
                'sandbox' => [
                    'udev-central' => ['name' => 'UDEV Central Sandbox', 'is_active' => true],
                    'MRC-0002' => ['name' => 'UDEV Central MRC-0002 Sandbox', 'is_active' => true],
                    'larissama' => ['name' => 'Larissama Sandbox', 'is_active' => true],
                ],
                'production' => [
                    'udev-central' => ['name' => 'UDEV Central Prod', 'is_active' => true],
                    'MRC-0002' => ['name' => 'UDEV Central MRC-0002 Prod', 'is_active' => true],
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

        // Merchant Larissama: DANA MDR 0.50% (500) ditanggung merchant, UDEV fee (1.500) dicatat internal, customer bebas biaya
        $larisCalc = $service->calculateFee('larissama', 'qris', 100000, 'sandbox');
        $this->assertSame(500.0, (float) $larisCalc['dana_fee_amount']);
        $this->assertSame(1500.0, (float) $larisCalc['udev_fee_amount']);
        $this->assertSame(0.0, (float) $larisCalc['customer_fee']);
        $this->assertSame(100000.0, (float) $larisCalc['total_amount']);
        $this->assertSame(99500.0, (float) $larisCalc['estimated_net_settlement']);

        // Central Merchant: menggunakan tarif global 0.70% (700) ditanggung merchant, customer bebas biaya
        $centralCalc = $service->calculateFee('udev-central', 'qris', 100000, 'sandbox');
        $this->assertSame(700.0, (float) $centralCalc['dana_fee_amount']);
        $this->assertSame(0.0, (float) $centralCalc['udev_fee_amount']);
        $this->assertSame(0.0, (float) $centralCalc['customer_fee']);
        $this->assertSame(100000.0, (float) $centralCalc['total_amount']);
        $this->assertSame(99300.0, (float) $centralCalc['estimated_net_settlement']);
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

        // Production tanpa tarif untuk metode non-QRIS (dana_wallet) harus fail-closed
        $this->expectException(ValidationException::class);
        $service->calculateFee('udev-central', 'dana_wallet', 100000, 'production');
    }

    public function test_production_va_without_fee_fails_closed(): void
    {
        $service = app(DanaFeeService::class);

        // Production tanpa tarif untuk Virtual Account harus fail-closed
        $this->expectException(ValidationException::class);
        $service->calculateFee('udev-central', 'va_bri', 100000, 'production');
    }

    public function test_production_qris_zero_mdr_is_valid_and_does_not_fail_closed(): void
    {
        $service = app(DanaFeeService::class);

        // QRIS Production untuk UDEV Central (kategori UMI) valid otomatis dengan MDR 0% dan TIDAK fail-closed
        $result = $service->calculateFee('udev-central', 'qris', 100000, 'production');

        $this->assertSame(0.0, (float) $result['dana_fee_amount']);
        $this->assertSame(0.0, (float) $result['customer_fee']);
        $this->assertSame(100000.0, (float) $result['total_amount']);
        $this->assertSame(0.0, (float) $result['estimated_merchant_fee']);
        $this->assertSame(100000.0, (float) $result['estimated_net_settlement']);
        $this->assertTrue($result['is_automatic_qris_mdr']);
        $this->assertSame('tier_le_500k', $result['qris_mdr_tier']);
        $this->assertSame('UMI', $result['merchant_category']);
        $this->assertSame('UDEV001', $result['shop_id']);
    }

    public function test_qris_automatic_mdr_boundary_500k_and_500k_plus_one(): void
    {
        $service = app(DanaFeeService::class);

        // 1. Batas Tepat Rp 500.000: MDR 0%
        $res500k = $service->calculateFee('udev-central', 'qris', 500000, 'production');
        $this->assertSame(0.0, (float) $res500k['dana_fee_amount']);
        $this->assertSame(0.0, (float) $res500k['dana_fee_value']);
        $this->assertSame(0.0, (float) $res500k['customer_fee']);
        $this->assertSame(500000.0, (float) $res500k['total_amount']);
        $this->assertSame(0.0, (float) $res500k['estimated_merchant_fee']);
        $this->assertSame(500000.0, (float) $res500k['estimated_net_settlement']);
        $this->assertSame('tier_le_500k', $res500k['qris_mdr_tier']);

        // 2. Batas Rp 500.001: MDR 0,3% dari seluruh nominal transaksi (500.001 * 0.003 = 1.500)
        // MDR ditanggung merchant UDEV, customer tetap bayar Rp 500.001, net settlement berkurang 1.500
        $res500k1 = $service->calculateFee('udev-central', 'qris', 500001, 'production');
        $this->assertSame(1500.0, (float) $res500k1['dana_fee_amount']);
        $this->assertSame(0.30, (float) $res500k1['dana_fee_value']);
        $this->assertSame(0.0, (float) $res500k1['customer_fee']);
        $this->assertSame(500001.0, (float) $res500k1['total_amount']);
        $this->assertSame(1500.0, (float) $res500k1['estimated_merchant_fee']);
        $this->assertSame(498501.0, (float) $res500k1['estimated_net_settlement']);
        $this->assertSame('tier_gt_500k', $res500k1['qris_mdr_tier']);
    }

    public function test_qris_with_separate_udev_platform_service_fee(): void
    {
        // Biaya layanan platform UDEV tetap terpisah dan mengikuti konfigurasi
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

        $service = app(DanaFeeService::class);

        // Tagihan Rp 600.000 (> 500k): MDR 0,3% = 1.800 ditanggung UDEV merchant, Pelanggan bebas surcharge (Rp 0), UDEV Fee Rp 1.000 tercatat internal
        $res = $service->calculateFee('udev-central', 'qris', 600000, 'production');

        $this->assertSame(1800.0, (float) $res['dana_fee_amount']);
        $this->assertSame(1000.0, (float) $res['udev_fee_amount']);
        $this->assertSame(0.0, (float) $res['customer_fee']); // Pelanggan bebas surcharge sesuai kontrak DANA
        $this->assertSame(600000.0, (float) $res['total_amount']); // 600.000 pokok murni
        $this->assertSame(1800.0, (float) $res['estimated_merchant_fee']); // MDR ditanggung UDEV
        $this->assertSame(598200.0, (float) $res['estimated_net_settlement']); // 600.000 - 1.800
    }

    public function test_empty_or_unknown_merchant_code_fails_closed(): void
    {
        $service = app(DanaFeeService::class);

        // 1. Merchant kosong wajib fail-closed
        try {
            $service->calculateFee('', 'qris', 100000, 'production');
            $this->fail('Seharusnya melempar ValidationException untuk merchant kosong');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('merchant_code', $e->errors());
        }

        // 2. Merchant tidak dikenal wajib fail-closed dan tidak boleh fallback ke UMI otomatis
        try {
            $service->calculateFee('unknown-external-merchant', 'qris', 100000, 'production');
            $this->fail('Seharusnya melempar ValidationException untuk merchant tidak dikenal');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment_method', $e->errors());
        }
    }

    public function test_mrc_0002_explicit_central_merchant_receives_automatic_umi_mdr(): void
    {
        $service = app(DanaFeeService::class);

        // Merchant pusat MRC-0002 teridentifikasi eksplisit dan menerima MDR UMI 0% untuk <= 500k
        $res500k = $service->calculateFee('MRC-0002', 'qris', 500000, 'production');
        $this->assertSame(0.0, (float) $res500k['dana_fee_amount']);
        $this->assertSame(500000.0, (float) $res500k['estimated_net_settlement']);
        $this->assertTrue($res500k['is_automatic_qris_mdr']);
        $this->assertSame('tier_le_500k', $res500k['qris_mdr_tier']);
        $this->assertSame('UMI', $res500k['merchant_category']);
        $this->assertSame('UDEV001', $res500k['shop_id']);

        // MRC-0002 > 500k menerima MDR 0,3%
        $res600k = $service->calculateFee('MRC-0002', 'qris', 600000, 'production');
        $this->assertSame(1800.0, (float) $res600k['dana_fee_amount']);
        $this->assertSame(598200.0, (float) $res600k['estimated_net_settlement']);
        $this->assertSame('tier_gt_500k', $res600k['qris_mdr_tier']);
    }

    public function test_larissama_subscription_checkout_uses_central_merchant_mrc_0002_with_automatic_qris_mdr(): void
    {
        config()->set('services.dana_gateway.merchant_api_keys.sandbox.MRC-0002', 'key-mrc0002-sandbox');
        config()->set('services.dana_gateway.merchants.sandbox.MRC-0002', ['name' => 'UDEV-SANBOX-BILL', 'is_active' => true]);
        config()->set('services.dana_gateway.merchant_api_keys.production.MRC-0002', 'key-mrc0002-prod');
        config()->set('services.dana_gateway.merchants.production.MRC-0002', ['name' => 'UDEV-PROD-BILL', 'is_active' => true]);

        \App\Models\DanaEnvironmentSetting::query()->updateOrCreate(['gateway' => 'dana'], ['primary_merchant_code' => 'MRC-0002']);

        $application = Application::factory()->create(['name' => 'Larissama POS', 'code' => 'larissama']);
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id, 'price' => 500000]);

        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '500000.00',
            'currency' => 'IDR',
            'due_at' => now()->addDay(),
        ]);

        $this->fakeCheckoutGateway('gateway-pay-laris-mrc0002');

        Sanctum::actingAs($this->admin, ['admin:access']);

        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'qris',
        ], ['Idempotency-Key' => 'test-idemp-laris-001'])
            ->assertOk();

        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();

        // Pembayaran langganan Larissama diarahkan ke merchant pusat MRC-0002
        $this->assertSame('MRC-0002', $attempt->gateway_merchant_code);
        $this->assertSame('udev_central', $attempt->metadata['billing_recipient'] ?? null);

        // Snapshot MDR otomatis UMI terpasang
        $snapshot = $attempt->metadata['fee_breakdown'];
        $this->assertSame('MRC-0002', $snapshot['gateway_merchant_code']);
        $this->assertSame('UMI', $snapshot['merchant_category']);
        $this->assertSame('UDEV001', $snapshot['shop_id']);
        $this->assertTrue($snapshot['is_automatic_qris_mdr']);
        $this->assertSame(0.0, (float) $snapshot['dana_fee_amount']);
        $this->assertSame(500000.0, (float) $snapshot['estimated_net_settlement']);
    }

    public function test_historical_payment_attempt_preserves_method_and_idempotency_does_not_falsely_convert(): void
    {
        $invoice = Invoice::factory()->create(['amount' => '100000.00', 'currency' => 'IDR']);

        // Simulasi percobaan pembayaran historis lama tanpa metadata payment_method
        $historicalAttempt = PaymentAttempt::query()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'provider' => 'dana',
            'gateway_payment_id' => 'pay-hist-001',
            'merchant_reference' => 'ord-hist-001',
            'gateway_merchant_code' => 'udev-central',
            'gateway_environment' => 'sandbox',
            'amount' => '100000.00',
            'status' => PaymentAttempt::StatusPending,
            'currency' => 'IDR',
            'idempotency_key' => 'checkout:'.$invoice->id.':test-idemp-hist',
            'requested_at' => now(),
            'metadata' => [
                // Catatan: Tidak ada 'payment_method' pada percobaan historis
            ],
        ]);

        // Resource harus tetap menjaga identitas historis sebagai dana_wallet, bukan tertimpa qris
        $resource = (new \App\Http\Resources\InvoiceCheckoutResource($historicalAttempt))->toArray(request());
        $this->assertSame('dana_wallet', $resource['payment_method']);

        // Idempotency check: mencoba checkout ulang dengan metode 'qris' harus terdeteksi sebagai konflik parameter
        $checkoutService = app(\App\Services\CheckoutService::class);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $checkoutService->startCheckout($invoice, 'test-idemp-hist', 'qris');
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
            ->assertJsonPath('data.total_fee', 0)
            ->assertJsonPath('data.total_amount', 100000)
            ->assertJsonPath('data.estimated_net_settlement', 99300);
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
        ], ['Idempotency-Key' => 'test-idemp-fee-01'])->assertOk();

        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();

        // Kontrak DANA: Bebas biaya tambahan bagi pelanggan (Zero Surcharge)
        // Total yang dibebankan ke pelanggan adalah 100.000 pokok
        $this->assertSame('100000.00', (string) $attempt->amount);

        // Snapshot tersimpan pada metadata
        $snapshot = $attempt->metadata['fee_breakdown'];
        $this->assertNotNull($snapshot);
        $this->assertSame(700.0, (float) $snapshot['dana_fee']);
        $this->assertSame('percent', $snapshot['dana_fee_unit']);
        $this->assertSame(1500.0, (float) $snapshot['udev_fee']);
        $this->assertSame('rupiah', $snapshot['udev_fee_unit']);
        $this->assertSame(0.0, (float) $snapshot['total_fee']);
        $this->assertSame(100000.0, (float) $snapshot['total_amount']);

        // Estimasi net settlement merchant dari DANA: 100.000 - 700 = 99.300
        $this->assertSame(99300.0, (float) $snapshot['estimated_net_settlement']);
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

    public function test_fee_calculator_regression_100k_without_fee_and_with_dana_qris_and_udev_fee(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        // SKENARIO 1: Tagihan Rp100.000 TANPA TARIF
        // Temuan UAT sebelumnya: menghasilkan estimated_net_settlement 99.300 karena MDR legacy absorbed_by_merchant.
        // Sekarang: Hak pemilik aplikasi HARUS tetap utuh Rp100.000 dan customer fee Rp0.
        $resWithoutFee = $this->postJson('/api/v1/admin/dana-gateway/calculate-fee', [
            'payment_method' => 'qris',
            'amount' => 100000,
            'environment' => 'sandbox',
        ]);

        $resWithoutFee->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.base_amount', 100000)
            ->assertJsonPath('data.dana_fee', 0)
            ->assertJsonPath('data.dana_fee_amount', 0)
            ->assertJsonPath('data.udev_fee', 0)
            ->assertJsonPath('data.udev_fee_amount', 0)
            ->assertJsonPath('data.total_fee', 0)
            ->assertJsonPath('data.total_amount', 100000)
            ->assertJsonPath('data.estimated_net_settlement', 100000); // 100% hak pemilik aplikasi utuh, BUKAN 99.300!

        // SKENARIO 2: Tagihan Rp100.000 DENGAN TARIF DANA 0,7% + UDEV Rp500
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

        DanaPaymentFeeSetting::query()->create([
            'gateway_merchant_code' => null,
            'application_id' => null,
            'payment_method' => 'qris',
            'environment' => 'sandbox',
            'fee_type' => 'udev',
            'fee_value' => 500.00,
            'fee_unit' => 'rupiah',
            'is_active' => true,
        ]);

        $resWithFee = $this->postJson('/api/v1/admin/dana-gateway/calculate-fee', [
            'payment_method' => 'qris',
            'amount' => 100000,
            'environment' => 'sandbox',
        ]);

        $resWithFee->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.base_amount', 100000)
            ->assertJsonPath('data.dana_fee_amount', 700)
            ->assertJsonPath('data.dana_fee_unit', 'percent')
            ->assertJsonPath('data.udev_fee_amount', 500)
            ->assertJsonPath('data.udev_fee_unit', 'rupiah')
            ->assertJsonPath('data.total_fee', 0)
            ->assertJsonPath('data.total_amount', 100000) // Kontrak DANA: Zero surcharge pelanggan (100.000 pokok murni)
            ->assertJsonPath('data.estimated_net_settlement', 99300); // 100.000 - 700 MDR DANA
    }

    public function test_production_temporary_zero_fee_fee_not_found_and_nonzero_fee_regression(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        // --- SKENARIO 1: TARIF DANA Rp0 SEMENTARA UNTUK UAT PRODUCTION ---
        // 1. Simpan tarif DANA Rp0 via API
        $createRes = $this->postJson('/api/v1/admin/dana-gateway/fees', [
            'payment_method' => 'qris',
            'environment' => 'production',
            'fee_type' => 'dana',
            'fee_value' => 0.00,
            'fee_unit' => 'rupiah',
            'is_active' => true,
        ]);

        $createRes->assertCreated()
            ->assertJsonPath('data.fee_value', '0.00')
            ->assertJsonPath('data.notes', 'Tarif DANA Rp0 sementara untuk UAT');

        // 2. Kalkulator simulasi: DANA Rp0 diizinkan, fail-closed tidak terpicu, hak utuh Rp100.000
        $calcRes = $this->postJson('/api/v1/admin/dana-gateway/calculate-fee', [
            'payment_method' => 'qris',
            'amount' => 100000,
            'environment' => 'production',
        ]);

        $calcRes->assertOk()
            ->assertJsonPath('data.dana_fee', 0)
            ->assertJsonPath('data.total_fee', 0)
            ->assertJsonPath('data.total_amount', 100000)
            ->assertJsonPath('data.is_temporary_zero_fee', true)
            ->assertJsonPath('data.estimated_net_settlement', 100000);

        // --- SKENARIO 2: TARIF TIDAK DITEMUKAN / TIDAK AKTIF DI PRODUCTION (FAIL-CLOSED) ---
        // Metode 'dana_wallet' belum dikonfigurasi di Production -> harus fail-closed 422
        $this->expectException(ValidationException::class);
        $service = app(DanaFeeService::class);
        $service->calculateFee('udev-central', 'dana_wallet', 100000, 'production');
    }

    public function test_production_nonzero_fee_charges_customer_without_reducing_app_owner_share(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        // --- SKENARIO 3: TARIF NONZERO RESMI DI PRODUCTION (KONTRAK DANA-UDUMBARA) ---
        // DANA Wallet: 1.30% (Rp 1.300) sesuai kontrak
        DanaPaymentFeeSetting::query()->create([
            'gateway_merchant_code' => null,
            'application_id' => null,
            'payment_method' => 'dana_wallet',
            'environment' => 'production',
            'fee_type' => 'dana',
            'fee_value' => 1.30,
            'fee_unit' => 'percent',
            'is_active' => true,
            'notes' => 'Tarif resmi DANA Wallet Production (Kontrak 1,3%)',
        ]);

        // UDEV Platform: Rp 1.000
        DanaPaymentFeeSetting::query()->create([
            'gateway_merchant_code' => null,
            'application_id' => null,
            'payment_method' => 'dana_wallet',
            'environment' => 'production',
            'fee_type' => 'udev',
            'fee_value' => 1000.00,
            'fee_unit' => 'rupiah',
            'is_active' => true,
        ]);

        $res = $this->postJson('/api/v1/admin/dana-gateway/calculate-fee', [
            'payment_method' => 'dana_wallet',
            'amount' => 100000,
            'environment' => 'production',
        ]);

        $res->assertOk()
            ->assertJsonPath('data.base_amount', 100000)
            ->assertJsonPath('data.dana_fee_amount', 1300)
            ->assertJsonPath('data.udev_fee_amount', 1000)
            ->assertJsonPath('data.total_fee', 0) // Zero surcharge pelanggan
            ->assertJsonPath('data.total_amount', 100000) // 100.000 pokok murni
            ->assertJsonPath('data.is_temporary_zero_fee', false)
            ->assertJsonPath('data.estimated_net_settlement', 98700); // 100.000 - 1.300 MDR DANA
    }

    public function test_official_contract_rates_for_wallet_and_virtual_accounts(): void
    {
        $service = app(DanaFeeService::class);
        $service->ensureDefaultSettings('sandbox');
        $amount = 200000.0;

        // 1. DANA Wallet: 1,30% MDR (200.000 * 1,3% = 2.600)
        $wallet = $service->calculateFee('udev-central', 'dana_wallet', $amount, 'sandbox');
        $this->assertSame(2600.0, (float) $wallet['dana_fee_amount']);
        $this->assertSame(0.0, (float) $wallet['customer_fee']);
        $this->assertSame(200000.0, (float) $wallet['total_amount']);
        $this->assertSame(197400.0, (float) $wallet['estimated_net_settlement']);

        // 2. Virtual Accounts: Rp 1.500 per transaksi (BRI, Mandiri, BNI, CIMB, Panin - BTPN dikeluarkan dari kontrak)
        $vaMethods = ['va_bri', 'va_mandiri', 'va_bni', 'va_cimb', 'va_pani'];
        foreach ($vaMethods as $vaMethod) {
            $calc = $service->calculateFee('udev-central', $vaMethod, $amount, 'sandbox');
            $this->assertSame(1500.0, (float) $calc['dana_fee_amount'], "Gagal pada metode {$vaMethod}");
            $this->assertSame(0.0, (float) $calc['customer_fee'], "Gagal pada metode {$vaMethod}");
            $this->assertSame(200000.0, (float) $calc['total_amount'], "Gagal pada metode {$vaMethod}");
            $this->assertSame(198500.0, (float) $calc['estimated_net_settlement'], "Gagal pada metode {$vaMethod}");
        }
    }

    public function test_production_larissama_subscription_billing_succeeds_as_direct_udev_service(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        \App\Models\DanaEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'dana'],
            [
                'active_environment' => 'production',
                'primary_merchant_code' => 'MRC-0002',
                'is_production_ready' => true,
            ]
        );

        $merchants = config('services.dana_gateway.merchants', []);
        $merchants['production']['MRC-0002'] = ['name' => 'UDEV Central Production', 'is_active' => true];
        config()->set('services.dana_gateway.merchants', $merchants);

        $keys = config('services.dana_gateway.merchant_api_keys', []);
        $keys['production']['MRC-0002'] = 'prod-key-002';
        config()->set('services.dana_gateway.merchant_api_keys', $keys);

        config()->set('services.udev_billing.subscription_dana_production_merchant_code', 'MRC-0002');
        // Whitelist persetujuan pihak ketiga kosong; transaksi langganan resmi Udumbara tidak memerlukannya
        config()->set('services.dana_gateway.contract_approved_third_party_apps', []);

        // Larissama Production menggunakan kode APP0001
        $app = Application::factory()->create(['code' => 'APP0001', 'name' => 'Larissama POS']);
        $customer = ApplicationCustomer::factory()->create(['application_id' => $app->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $app->id, 'price' => 100000]);

        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '100000.00',
            'currency' => 'IDR',
            'due_at' => now()->addDay(),
        ]);

        $this->fakeCheckoutGateway('pay-prod-sub-direct', 'MRC-0002', 'production');

        // Pembayaran langganan resmi SaaS software Udumbara (Larissama) di Production berhasil
        $response = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'qris',
        ], ['Idempotency-Key' => 'idemp-laris-prod-sub-direct']);

        $response->assertOk();

        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame('MRC-0002', $attempt->gateway_merchant_code);
        $this->assertSame('production', $attempt->gateway_environment);
        $this->assertSame('100000.00', (string) $attempt->amount); // Zero customer surcharge
        $this->assertSame(0.0, (float) ($attempt->metadata['fee_breakdown']['customer_fee'] ?? 0.0));
        $this->assertSame('udev_central', $attempt->metadata['billing_recipient'] ?? null);
    }

    public function test_production_non_subscription_or_third_party_warung_transaction_is_blocked_fail_closed(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        \App\Models\DanaEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'dana'],
            [
                'active_environment' => 'production',
                'primary_merchant_code' => 'MRC-0002',
                'is_production_ready' => true,
            ]
        );

        $merchants = config('services.dana_gateway.merchants', []);
        $merchants['production']['MRC-0002'] = ['name' => 'UDEV Central Production', 'is_active' => true];
        config()->set('services.dana_gateway.merchants', $merchants);

        $keys = config('services.dana_gateway.merchant_api_keys', []);
        $keys['production']['MRC-0002'] = 'prod-key-002';
        config()->set('services.dana_gateway.merchant_api_keys', $keys);

        config()->set('services.udev_billing.subscription_dana_production_merchant_code', 'MRC-0002');
        config()->set('services.dana_gateway.contract_approved_third_party_apps', []); // Belum ada persetujuan

        $app = Application::factory()->create(['code' => 'APP0001', 'name' => 'Larissama POS']);
        $customer = ApplicationCustomer::factory()->create(['application_id' => $app->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $app->id, 'price' => 100000]);

        // Invoice non-langganan (transaksi belanja warung / retail pihak ketiga ditandai pada metadata)
        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '100000.00',
            'currency' => 'IDR',
            'due_at' => now()->addDay(),
            'metadata' => [
                'transaction_type' => 'warung_commerce',
            ],
        ]);

        $this->fakeCheckoutGateway('pay-prod-fail', 'MRC-0002', 'production');

        // Percobaan checkout non-langganan ke merchant pusat di Production wajib fail-closed (422)
        $response = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'qris',
        ], ['Idempotency-Key' => 'idemp-laris-prod-non-sub-blocked']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['invoice']);

        $errors = $response->json('errors.invoice', []);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('transaksi non-langganan / pembayaran warung pihak ketiga', implode(' ', $errors));
        $this->assertStringContainsString('belum memiliki persetujuan kontraktual tertulis resmi dari DANA', implode(' ', $errors));
    }

    public function test_production_non_subscription_succeeds_when_contract_approved(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        \App\Models\DanaEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'dana'],
            [
                'active_environment' => 'production',
                'primary_merchant_code' => 'MRC-0002',
                'is_production_ready' => true,
            ]
        );

        $merchants = config('services.dana_gateway.merchants', []);
        $merchants['production']['MRC-0002'] = ['name' => 'UDEV Central Production', 'is_active' => true];
        config()->set('services.dana_gateway.merchants', $merchants);

        $keys = config('services.dana_gateway.merchant_api_keys', []);
        $keys['production']['MRC-0002'] = 'prod-key-002';
        config()->set('services.dana_gateway.merchant_api_keys', $keys);

        config()->set('services.udev_billing.subscription_dana_production_merchant_code', 'MRC-0002');
        // Admin telah memverifikasi persetujuan tertulis DANA untuk Larissama (diinput kode APP0001)
        config()->set('services.dana_gateway.contract_approved_third_party_apps', ['APP0001']);

        $app = Application::factory()->create(['code' => 'APP0001', 'name' => 'Larissama POS']);
        $customer = ApplicationCustomer::factory()->create(['application_id' => $app->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $app->id, 'price' => 100000]);

        // Invoice non-langganan / warung commerce
        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '100000.00',
            'currency' => 'IDR',
            'due_at' => now()->addDay(),
            'metadata' => [
                'transaction_type' => 'warung_commerce',
            ],
        ]);

        $this->fakeCheckoutGateway('pay-prod-approved', 'MRC-0002', 'production');

        $response = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'qris',
        ], ['Idempotency-Key' => 'idemp-laris-prod-approved']);

        $response->assertOk();

        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame('MRC-0002', $attempt->gateway_merchant_code);
        $this->assertSame('production', $attempt->gateway_environment);
        $this->assertSame('100000.00', (string) $attempt->amount); // Zero surcharge
        $this->assertSame(0.0, (float) ($attempt->metadata['fee_breakdown']['customer_fee'] ?? 0.0));
    }

    public function test_production_external_third_party_app_is_blocked_without_dana_approval(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        \App\Models\DanaEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'dana'],
            [
                'active_environment' => 'production',
                'primary_merchant_code' => 'MRC-0002',
                'is_production_ready' => true,
            ]
        );

        $merchants = config('services.dana_gateway.merchants', []);
        $merchants['production']['MRC-0002'] = ['name' => 'UDEV Central Production', 'is_active' => true];
        config()->set('services.dana_gateway.merchants', $merchants);

        $keys = config('services.dana_gateway.merchant_api_keys', []);
        $keys['production']['MRC-0002'] = 'prod-key-002';
        config()->set('services.dana_gateway.merchant_api_keys', $keys);

        config()->set('services.udev_billing.subscription_dana_production_merchant_code', 'MRC-0002');
        config()->set('services.dana_gateway.contract_approved_third_party_apps', []); // Belum ada persetujuan

        // Aplikasi pihak ketiga di luar ekosistem resmi Udumbara
        $app = Application::factory()->create(['code' => 'APP9999', 'name' => 'Mitra Eksternal']);
        $customer = ApplicationCustomer::factory()->create(['application_id' => $app->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $app->id, 'price' => 100000]);

        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '100000.00',
            'currency' => 'IDR',
            'due_at' => now()->addDay(),
        ]);

        $this->fakeCheckoutGateway('pay-prod-fail-ext', 'MRC-0002', 'production');

        // Percobaan checkout aplikasi pihak ketiga tanpa persetujuan DANA di Production wajib fail-closed (422)
        $response = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'qris',
        ], ['Idempotency-Key' => 'idemp-ext-prod-blocked']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['invoice']);

        $errors = $response->json('errors.invoice', []);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("penagihan aplikasi pihak ketiga ('Mitra Eksternal (app9999)')", implode(' ', $errors));
        $this->assertStringContainsString('belum memiliki persetujuan kontraktual tertulis resmi dari DANA', implode(' ', $errors));
    }

    public function test_production_external_third_party_app_succeeds_when_approved_by_app_name_or_comma_separated_env(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        \App\Models\DanaEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'dana'],
            [
                'active_environment' => 'production',
                'primary_merchant_code' => 'MRC-0002',
                'is_production_ready' => true,
            ]
        );

        $merchants = config('services.dana_gateway.merchants', []);
        $merchants['production']['MRC-0002'] = ['name' => 'UDEV Central Production', 'is_active' => true];
        config()->set('services.dana_gateway.merchants', $merchants);

        $keys = config('services.dana_gateway.merchant_api_keys', []);
        $keys['production']['MRC-0002'] = 'prod-key-002';
        config()->set('services.dana_gateway.merchant_api_keys', $keys);

        config()->set('services.udev_billing.subscription_dana_production_merchant_code', 'MRC-0002');
        // Persetujuan diisi kode aplikasi pihak ketiga dalam bentuk string dipisah koma (format .env)
        config()->set('services.dana_gateway.contract_approved_third_party_apps', 'other_app,APP9999');

        $app = Application::factory()->create(['code' => 'APP9999', 'name' => 'Mitra Eksternal']);
        $customer = ApplicationCustomer::factory()->create(['application_id' => $app->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $app->id, 'price' => 100000]);

        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '100000.00',
            'currency' => 'IDR',
            'due_at' => now()->addDay(),
        ]);

        $this->fakeCheckoutGateway('pay-prod-approved-ext', 'MRC-0002', 'production');

        $response = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'qris',
        ], ['Idempotency-Key' => 'idemp-ext-prod-approved']);

        $response->assertOk();

        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame('MRC-0002', $attempt->gateway_merchant_code);
        $this->assertSame('production', $attempt->gateway_environment);
    }

    public function test_sandbox_larissama_central_merchant_allowed_without_production_block(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        config()->set('services.dana_gateway.contract_approved_third_party_apps', []); // Kosong

        $app = Application::factory()->create(['code' => 'APP0001', 'name' => 'Larissama POS']);
        $customer = ApplicationCustomer::factory()->create(['application_id' => $app->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $app->id, 'price' => 50000]);

        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '50000.00',
            'currency' => 'IDR',
            'due_at' => now()->addDay(),
        ]);

        $this->fakeCheckoutGateway('pay-sbx-laris-central');

        // Sandbox diizinkan tanpa blokir guard Production
        $response = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'qris',
        ], ['Idempotency-Key' => 'idemp-laris-sbx-ok']);

        $response->assertOk();

        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame('sandbox', $attempt->gateway_environment);
        $this->assertSame('50000.00', (string) $attempt->amount);
    }

    public function test_va_btpn_is_not_auto_seeded_and_fails_closed_in_production(): void
    {
        $service = app(DanaFeeService::class);
        $service->ensureDefaultSettings('sandbox');

        // Pastikan va_btpn tidak ada di OfficialStandardFees
        $this->assertArrayNotHasKey('va_btpn', DanaFeeService::OfficialStandardFees);

        // Pastikan tidak dibuat fee setting di DB untuk va_btpn
        $this->assertDatabaseMissing('dana_payment_fee_settings', [
            'payment_method' => 'va_btpn',
        ]);

        // Di Production, tanpa konfigurasi tarif resmi, wajib fail-closed
        $this->expectException(ValidationException::class);
        $service->calculateFee('udev-central', 'va_btpn', 100000, 'production');
    }

    public function test_settlement_fields_separation_and_actual_settlement_handling(): void
    {
        $service = app(DanaFeeService::class);
        $service->ensureDefaultSettings('sandbox');

        $calc = $service->calculateFee('udev-central', 'dana_wallet', 100000, 'sandbox');
        $this->assertSame(1300.0, (float) $calc['estimated_gateway_fee']);
        $this->assertSame(1300.0, (float) $calc['estimated_merchant_fee']);
        $this->assertSame(98700.0, (float) $calc['estimated_net_settlement']);
        $this->assertNull($calc['actual_gateway_fee']);
        $this->assertNull($calc['actual_settlement']);
        $this->assertSame('estimated', $calc['settlement_status']);

        $invoice = Invoice::factory()->create([
            'amount' => '100000.00',
            'currency' => 'IDR',
            'status' => Invoice::StatusPending,
        ]);

        $snapshot = $service->buildFeeSnapshot($invoice, 'dana_wallet', 'udev-central', 'sandbox');
        $this->assertSame(1300.0, (float) $snapshot['estimated_gateway_fee']);
        $this->assertSame(1300.0, (float) $snapshot['estimated_merchant_fee']);
        $this->assertSame(98700.0, (float) $snapshot['estimated_net_settlement']);
        $this->assertNull($snapshot['actual_gateway_fee']);
        $this->assertNull($snapshot['actual_settlement']);
        $this->assertSame('estimated', $snapshot['settlement_status']);
    }

    public function test_checkout_fails_closed_when_payment_method_is_disabled_on_gateway_merchant(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);
        $this->fakeCheckoutGateway();

        // Merchant udev-central mengonfigurasi disabled_payment_methods = ['dana_wallet']
        $merchants = config('services.dana_gateway.merchants', []);
        $merchants['sandbox']['udev-central'] = [
            'name' => 'UDEV Central',
            'is_active' => true,
            'disabled_payment_methods' => ['dana_wallet'],
        ];
        config()->set('services.dana_gateway.merchants', $merchants);

        $invoice = Invoice::factory()->create([
            'amount' => '100000.00',
            'currency' => 'IDR',
            'status' => Invoice::StatusPending,
        ]);

        // Walau ada fee setting, jika method tidak aktif di gateway, wajib fail-closed
        $response = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'provider' => 'dana',
            'payment_method' => 'dana_wallet',
        ], ['Idempotency-Key' => 'idemp-method-disabled']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payment_method']);
    }

    private function fakeCheckoutGateway(string $paymentId = 'pay-001'): void
    {
        Http::fake(function (HttpRequest $request) use ($paymentId): mixed {
            if ($request->method() === 'GET') {
                return Http::response(['data' => []]);
            }

            $data = $request->data();
            $orderId = (string) ($data['merchant_order_id'] ?? 'ord-001');
            $amount = (int) ($data['amount_minor'] ?? 10000000);

            return Http::response([
                'data' => [
                    'id' => $paymentId,
                    'merchant_order_id' => $orderId,
                    'amount_minor' => $amount,
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
            ]);
        });
    }
}
