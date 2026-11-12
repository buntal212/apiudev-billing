<?php

use App\Models\DanaPaymentFeeSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dana_payment_fee_settings')) {
            return;
        }

        // 1. Tambahkan kolom baru jika belum ada
        Schema::table('dana_payment_fee_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('dana_payment_fee_settings', 'gateway_merchant_code')) {
                $table->string('gateway_merchant_code', 60)->nullable()->after('application_id');
            }
            if (! Schema::hasColumn('dana_payment_fee_settings', 'scope_key')) {
                $table->string('scope_key', 120)->default('global')->after('id');
            }
        });

        // 2. Backfill scope_key seluruh data existing menggunakan DanaPaymentFeeSetting::buildScopeKey()
        $records = DB::table('dana_payment_fee_settings')->get();
        foreach ($records as $record) {
            $merchant = $record->gateway_merchant_code ?? null;
            $appId = $record->application_id ?? null;

            if (class_exists(DanaPaymentFeeSetting::class) && method_exists(DanaPaymentFeeSetting::class, 'buildScopeKey')) {
                $scopeKey = DanaPaymentFeeSetting::buildScopeKey($appId, $merchant);
            } else {
                $merchantTrimmed = trim((string) $merchant);
                if ($appId !== null && $merchantTrimmed !== '') {
                    $scopeKey = "app:{$appId}:merchant:{$merchantTrimmed}";
                } elseif ($appId !== null) {
                    $scopeKey = "app:{$appId}";
                } elseif ($merchantTrimmed !== '') {
                    $scopeKey = "merchant:{$merchantTrimmed}";
                } else {
                    $scopeKey = 'global';
                }
            }

            DB::table('dana_payment_fee_settings')
                ->where('id', $record->id)
                ->update(['scope_key' => $scopeKey]);
        }

        // 3. Deteksi duplikasi sebelum membuat unique index baru (fail-safe tanpa menghapus data)
        $duplicates = DB::table('dana_payment_fee_settings')
            ->select('scope_key', 'environment', 'payment_method', 'fee_type', DB::raw('COUNT(*) as duplicate_count'))
            ->groupBy('scope_key', 'environment', 'payment_method', 'fee_type')
            ->having('duplicate_count', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $duplicateList = $duplicates->map(function ($dup) {
                return "scope_key='{$dup->scope_key}', environment='{$dup->environment}', payment_method='{$dup->payment_method}', fee_type='{$dup->fee_type}' ({$dup->duplicate_count} records)";
            })->implode('; ');

            throw new \RuntimeException(
                "Gagal menerapkan unique index pada tabel 'dana_payment_fee_settings': terdeteksi duplikasi data existing: [{$duplicateList}]. " .
                "Data tidak dihapus otomatis demi menjaga integritas database. Harap rapikan duplikasi data secara manual sebelum melanjutkan migration."
            );
        }

        // 4. Periksa dan sesuaikan index
        $existingIndexes = collect(Schema::getIndexes('dana_payment_fee_settings'))->pluck('name')->filter()->all();

        if (in_array('dana_fee_settings_app_env_method_type_uq', $existingIndexes, true)) {
            Schema::table('dana_payment_fee_settings', function (Blueprint $table): void {
                $table->dropUnique('dana_fee_settings_app_env_method_type_uq');
            });
        }

        if (in_array('dana_fee_lookup_idx', $existingIndexes, true)) {
            Schema::table('dana_payment_fee_settings', function (Blueprint $table): void {
                $table->dropIndex('dana_fee_lookup_idx');
            });
        }

        Schema::table('dana_payment_fee_settings', function (Blueprint $table) use ($existingIndexes): void {
            if (! in_array('dana_setting_scope_lookup_idx', $existingIndexes, true)) {
                $table->index(['scope_key', 'environment', 'payment_method', 'fee_type'], 'dana_setting_scope_lookup_idx');
            }
            if (! in_array('dana_setting_merchant_env_active_idx', $existingIndexes, true)) {
                $table->index(['gateway_merchant_code', 'environment', 'is_active'], 'dana_setting_merchant_env_active_idx');
            }
            if (! in_array('dana_setting_app_env_active_idx', $existingIndexes, true)) {
                $table->index(['application_id', 'environment', 'is_active'], 'dana_setting_app_env_active_idx');
            }
            if (! in_array('dana_setting_scope_env_method_type_uq', $existingIndexes, true)) {
                $table->unique(['scope_key', 'environment', 'payment_method', 'fee_type'], 'dana_setting_scope_env_method_type_uq');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dana_payment_fee_settings')) {
            return;
        }

        $existingIndexes = collect(Schema::getIndexes('dana_payment_fee_settings'))->pluck('name')->filter()->all();

        Schema::table('dana_payment_fee_settings', function (Blueprint $table) use ($existingIndexes): void {
            if (in_array('dana_setting_scope_env_method_type_uq', $existingIndexes, true)) {
                $table->dropUnique('dana_setting_scope_env_method_type_uq');
            }
            if (in_array('dana_setting_scope_lookup_idx', $existingIndexes, true)) {
                $table->dropIndex('dana_setting_scope_lookup_idx');
            }
            if (in_array('dana_setting_merchant_env_active_idx', $existingIndexes, true)) {
                $table->dropIndex('dana_setting_merchant_env_active_idx');
            }
            if (in_array('dana_setting_app_env_active_idx', $existingIndexes, true)) {
                $table->dropIndex('dana_setting_app_env_active_idx');
            }

            if (! in_array('dana_fee_settings_app_env_method_type_uq', $existingIndexes, true)) {
                $table->unique(['application_id', 'environment', 'payment_method', 'fee_type'], 'dana_fee_settings_app_env_method_type_uq');
            }
            if (! in_array('dana_fee_lookup_idx', $existingIndexes, true)) {
                $table->index(['application_id', 'environment', 'payment_method', 'fee_type'], 'dana_fee_lookup_idx');
            }

            if (Schema::hasColumn('dana_payment_fee_settings', 'gateway_merchant_code')) {
                $table->dropColumn('gateway_merchant_code');
            }
            if (Schema::hasColumn('dana_payment_fee_settings', 'scope_key')) {
                $table->dropColumn('scope_key');
            }
        });
    }
};
