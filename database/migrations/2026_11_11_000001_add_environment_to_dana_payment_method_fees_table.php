<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('dana_payment_method_fees')) {
            Schema::table('dana_payment_method_fees', function (Blueprint $table): void {
                if (! Schema::hasColumn('dana_payment_method_fees', 'environment')) {
                    $table->string('environment', 20)->default('sandbox')->after('merchant_code');
                }
            });

            // Update unique key to include environment
            try {
                Schema::table('dana_payment_method_fees', function (Blueprint $table): void {
                    $table->dropUnique('dana_fee_merchant_method_uq');
                    $table->unique(['merchant_code', 'environment', 'payment_method'], 'dana_fee_merchant_env_method_uq');
                });
            } catch (\Throwable) {
                // Ignore if unique index already migrated or different
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('dana_payment_method_fees')) {
            try {
                Schema::table('dana_payment_method_fees', function (Blueprint $table): void {
                    $table->dropUnique('dana_fee_merchant_env_method_uq');
                    $table->unique(['merchant_code', 'payment_method'], 'dana_fee_merchant_method_uq');
                });
            } catch (\Throwable) {
                // Ignore
            }

            Schema::table('dana_payment_method_fees', function (Blueprint $table): void {
                if (Schema::hasColumn('dana_payment_method_fees', 'environment')) {
                    $table->dropColumn('environment');
                }
            });
        }
    }
};
