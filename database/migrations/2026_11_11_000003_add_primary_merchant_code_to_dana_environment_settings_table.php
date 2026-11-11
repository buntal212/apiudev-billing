<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('dana_environment_settings')) {
            Schema::table('dana_environment_settings', function (Blueprint $table): void {
                if (! Schema::hasColumn('dana_environment_settings', 'primary_merchant_code')) {
                    $table->string('primary_merchant_code', 60)
                        ->default('udev-central')
                        ->after('active_environment');
                }
            });

            // Pertahankan merchant utama existing (udev-central) untuk baris data yang bernilai null atau kosong
            try {
                DB::table('dana_environment_settings')
                    ->where(function ($query): void {
                        $query->whereNull('primary_merchant_code')
                            ->orWhere('primary_merchant_code', '');
                    })
                    ->update(['primary_merchant_code' => 'udev-central']);
            } catch (Throwable) {
                // Abaikan jika driver/database testing berbeda atau tabel masih kosong
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('dana_environment_settings')) {
            Schema::table('dana_environment_settings', function (Blueprint $table): void {
                if (Schema::hasColumn('dana_environment_settings', 'primary_merchant_code')) {
                    $table->dropColumn('primary_merchant_code');
                }
            });
        }
    }
};
