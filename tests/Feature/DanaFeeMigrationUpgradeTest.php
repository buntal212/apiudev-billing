<?php

namespace Tests\Feature;

use App\Models\DanaPaymentFeeSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DanaFeeMigrationUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_upgrades_legacy_schema_backfills_scope_key_and_sets_unique_index(): void
    {
        // 1. Verifikasi tabel saat ini sudah memiliki kolom baru dari migration lengkap
        $this->assertTrue(Schema::hasTable('dana_payment_fee_settings'));
        $this->assertTrue(Schema::hasColumn('dana_payment_fee_settings', 'gateway_merchant_code'));
        $this->assertTrue(Schema::hasColumn('dana_payment_fee_settings', 'scope_key'));

        // 2. Simulasikan skenario legacy: rollback migrasi baru
        $newMigration = require database_path('migrations/2026_11_12_103000_add_gateway_merchant_code_and_scope_key_to_dana_payment_fee_settings_table.php');
        $newMigration->down();

        // Verifikasi skema kembali ke kondisi legacy Production (tanpa kolom baru)
        $this->assertFalse(Schema::hasColumn('dana_payment_fee_settings', 'gateway_merchant_code'));
        $this->assertFalse(Schema::hasColumn('dana_payment_fee_settings', 'scope_key'));

        // 3. Buat aplikasi dan masukkan data legacy
        $app = \App\Models\Application::factory()->create();
        $now = now();
        DB::table('dana_payment_fee_settings')->insert([
            [
                'application_id' => null,
                'payment_method' => 'qris',
                'environment' => 'sandbox',
                'fee_type' => 'dana',
                'fee_value' => 0.70,
                'fee_unit' => 'percent',
                'is_active' => 1,
                'notes' => 'Legacy Global QRIS',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'application_id' => $app->id,
                'payment_method' => 'qris',
                'environment' => 'sandbox',
                'fee_type' => 'dana',
                'fee_value' => 0.50,
                'fee_unit' => 'percent',
                'is_active' => 1,
                'notes' => "Legacy App {$app->id} QRIS",
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // 4. Jalankan migrasi upgrade baru
        $newMigration->up();

        // 5. Verifikasi kolom baru dan hasil backfill
        $this->assertTrue(Schema::hasColumn('dana_payment_fee_settings', 'gateway_merchant_code'));
        $this->assertTrue(Schema::hasColumn('dana_payment_fee_settings', 'scope_key'));

        $globalRecord = DB::table('dana_payment_fee_settings')
            ->whereNull('application_id')
            ->where('payment_method', 'qris')
            ->first();

        $this->assertNotNull($globalRecord);
        $this->assertSame('global', $globalRecord->scope_key);

        $appRecord = DB::table('dana_payment_fee_settings')
            ->where('application_id', $app->id)
            ->where('payment_method', 'qris')
            ->first();

        $this->assertNotNull($appRecord);
        $this->assertSame("app:{$app->id}", $appRecord->scope_key);

        // 6. Verifikasi unique constraint baru mencegah duplikasi scope_key
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('dana_payment_fee_settings')->insert([
            'scope_key' => 'global',
            'gateway_merchant_code' => null,
            'application_id' => null,
            'payment_method' => 'qris',
            'environment' => 'sandbox',
            'fee_type' => 'dana',
            'fee_value' => 0.80,
            'fee_unit' => 'percent',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_migration_detects_duplicates_before_creating_unique_index_and_does_not_delete_data(): void
    {
        $newMigration = require database_path('migrations/2026_11_12_103000_add_gateway_merchant_code_and_scope_key_to_dana_payment_fee_settings_table.php');
        $newMigration->down();

        $now = now();

        // Buat 2 record legacy dengan application_id = null yang sama
        // Pada MySQL/SQLite lama tanpa scope_key unik, ini bisa terjadi jika null tidak dianggap unik
        DB::table('dana_payment_fee_settings')->insert([
            [
                'application_id' => null,
                'payment_method' => 'qris',
                'environment' => 'sandbox',
                'fee_type' => 'dana',
                'fee_value' => 0.70,
                'fee_unit' => 'percent',
                'is_active' => 1,
                'notes' => 'Duplicate 1',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'application_id' => null,
                'payment_method' => 'qris',
                'environment' => 'sandbox',
                'fee_type' => 'dana',
                'fee_value' => 0.75,
                'fee_unit' => 'percent',
                'is_active' => 0,
                'notes' => 'Duplicate 2',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('terdeteksi duplikasi data existing');

        try {
            $newMigration->up();
        } finally {
            // Pastikan data tidak dihapus otomatis
            $count = DB::table('dana_payment_fee_settings')->count();
            $this->assertSame(2, $count, 'Migration tidak boleh menghapus data existing secara otomatis saat ada duplikasi.');
        }
    }
}
