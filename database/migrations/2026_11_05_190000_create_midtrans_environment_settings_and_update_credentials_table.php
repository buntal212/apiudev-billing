<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Update unique constraint on midtrans_merchant_credentials to allow separate credentials per environment
        Schema::table('midtrans_merchant_credentials', function (Blueprint $table): void {
            $table->dropUnique('midtrans_merchant_credentials_merchant_code_unique');
            if (Schema::hasIndex('midtrans_merchant_credentials', 'midtrans_cred_merchant_env_idx')) {
                $table->dropIndex('midtrans_cred_merchant_env_idx');
            }
            $table->unique(['merchant_code', 'environment'], 'midtrans_merchant_env_unique');
        });

        // 2. Create midtrans_environment_settings table to manage active environment & admin confirmation state
        Schema::create('midtrans_environment_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('gateway', 30)->default('midtrans')->unique();
            $table->string('active_environment', 20)->default('sandbox');
            $table->boolean('is_production_ready')->default(false);
            $table->timestamp('production_activated_at')->nullable();
            $table->foreignId('production_activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_switched_at')->nullable();
            $table->foreignId('last_switched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('activation_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('midtrans_environment_settings');

        Schema::table('midtrans_merchant_credentials', function (Blueprint $table): void {
            $table->dropUnique('midtrans_merchant_env_unique');
            $table->unique('merchant_code', 'midtrans_merchant_credentials_merchant_code_unique');
            $table->index(['merchant_code', 'environment'], 'midtrans_cred_merchant_env_idx');
        });
    }
};
