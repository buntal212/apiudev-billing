<?php

namespace Tests\Feature;

use App\Models\DanaEnvironmentSetting;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DanaEnvironmentSettingSchemaMismatchTest extends TestCase
{
    use RefreshDatabase;

    private const string Table = 'dana_environment_settings';

    private const string Column = 'primary_merchant_code';

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
                ],
                'production' => [
                    'udev-central' => 'key-central-prod',
                    'MRC-0002' => 'key-mrc0002-prod',
                ],
            ],
            'merchants' => [
                'sandbox' => [
                    'udev-central' => ['name' => 'UDEV Central Sandbox', 'is_active' => true],
                    'MRC-0002' => ['name' => 'UDEV-SANBOX-BILL', 'is_active' => true],
                ],
                'production' => [
                    'udev-central' => ['name' => 'UDEV Central Prod', 'is_active' => true],
                    'MRC-0002' => ['name' => 'UDEV-PROD-BILL', 'is_active' => true],
                ],
            ],
        ]);

        config()->set('services.udev_billing.subscription_dana_sandbox_merchant_code', 'udev-central');
        config()->set('services.udev_billing.subscription_dana_production_merchant_code', 'udev-central');
        config()->set('services.dana_merchant_provisioning.base_url', 'https://dana-payment.test');
        config()->set('services.dana_merchant_provisioning.service_token', 'test-service-token');
    }

    public function test_migration_adds_primary_merchant_code_column_to_dana_environment_settings_table(): void
    {
        $this->assertTrue(Schema::hasTable(self::Table), 'Tabel dana_environment_settings harus ada.');
        $this->assertTrue(Schema::hasColumn(self::Table, self::Column), 'Kolom primary_merchant_code harus ada.');
    }

    public function test_migration_is_idempotent_and_repairs_missing_column_on_live_table(): void
    {
        // 1. Simulasikan kondisi database live: kolom primary_merchant_code belum ada
        if (Schema::hasColumn(self::Table, self::Column)) {
            Schema::table(self::Table, function (Blueprint $table): void {
                $table->dropColumn(self::Column);
            });
        }
        $this->assertFalse(Schema::hasColumn(self::Table, self::Column));

        // 2. Simulasikan row setting existing di live database
        DB::table(self::Table)->truncate();
        DB::table(self::Table)->insert([
            'gateway' => 'dana',
            'active_environment' => 'sandbox',
            'is_production_ready' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Jalankan migration baru (up)
        $migration = $this->migration();
        $migration->up();

        // 4. Pastikan kolom primary_merchant_code telah ditambahkan
        $this->assertTrue(Schema::hasColumn(self::Table, self::Column));

        // 5. Pastikan row existing mempertahankan merchant utama default 'udev-central'
        $existingSetting = DB::table(self::Table)->where('gateway', 'dana')->first();
        $this->assertNotNull($existingSetting);
        $this->assertSame('udev-central', $existingSetting->primary_merchant_code);

        // 6. Jalankan migration up untuk kedua kalinya (idempotensi) tanpa exception
        $migration->up();
        $this->assertTrue(Schema::hasColumn(self::Table, self::Column));
    }

    public function test_set_primary_merchant_by_id_and_code_succeeds_without_unknown_column_error(): void
    {
        $this->mockProvisioningClient();

        // Endpoint set-primary by ID: POST /api/v1/admin/dana-gateway-merchants/{id}/set-primary
        $response = $this->actingAdmin()
            ->postJson('/api/v1/admin/dana-gateway-merchants/2/set-primary', [
                'notes' => 'Aktivasi MRC-0002 sebagai merchant utama',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.merchant_id', 2)
            ->assertJsonPath('data.merchant_code', 'MRC-0002')
            ->assertJsonPath('data.primary_merchant_code', 'MRC-0002');

        // Pastikan tersimpan pada database dan dibaca konsisten oleh model
        $this->assertDatabaseHas(self::Table, [
            'gateway' => 'dana',
            'primary_merchant_code' => 'MRC-0002',
        ]);
        $this->assertSame('MRC-0002', DanaEnvironmentSetting::getPrimaryMerchantCode());

        // Kembalikan ke udev-central via endpoint environment set-primary-merchant
        $this->actingAdmin()
            ->postJson('/api/v1/admin/dana-gateway-merchants/environment/set-primary-merchant', [
                'merchant_code' => 'udev-central',
            ])->assertOk()
            ->assertJsonPath('data.primary_merchant_code', 'udev-central');

        $this->assertSame('udev-central', DanaEnvironmentSetting::getPrimaryMerchantCode());
    }

    public function test_migration_down_drops_column_cleanly(): void
    {
        $migration = $this->migration();

        $this->assertTrue(Schema::hasColumn(self::Table, self::Column));

        // Rollback
        $migration->down();
        $this->assertFalse(Schema::hasColumn(self::Table, self::Column));

        // Migrate back up to leave database clean for subsequent tests
        $migration->up();
        $this->assertTrue(Schema::hasColumn(self::Table, self::Column));
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_11_000003_add_primary_merchant_code_to_dana_environment_settings_table.php');
    }

    private function actingAdmin(): self
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        return $this;
    }

    private function mockProvisioningClient(): void
    {
        Http::fake([
            '*/api/v1/internal/merchants*' => function (HttpRequest $request): mixed {
                $url = $request->url();
                if (preg_match('#/merchants/(\d+)#', $url, $m)) {
                    $id = (int) $m[1];
                    $code = $id === 2 ? 'MRC-0002' : 'udev-central';

                    return Http::response([
                        'data' => [
                            'merchant_id' => $id,
                            'merchant_code' => $code,
                            'merchant_name' => ucfirst($code),
                            'environment' => 'sandbox',
                            'merchant_status' => 'active',
                            'provisioning_status' => 'provisioned',
                            'credential_status' => 'configured',
                            'dana_configuration_status' => 'available',
                            'provisioned_at' => now()->toIso8601String(),
                        ],
                    ]);
                }

                return Http::response(['data' => []]);
            },
        ]);
    }
}
