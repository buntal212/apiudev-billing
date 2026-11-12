<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dana_payment_fee_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('gateway_merchant_code', 60)->nullable();
            $table->foreignId('application_id')->nullable()->constrained('applications')->cascadeOnDelete();
            $table->string('scope_key', 120)->default('global');
            $table->string('payment_method', 50);
            $table->string('environment', 20)->default('sandbox');
            $table->string('fee_type', 20)->default('dana');
            $table->decimal('fee_value', 15, 2)->default(0.00);
            $table->string('fee_unit', 20)->default('rupiah');
            $table->boolean('is_active')->default(true);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->index(['scope_key', 'environment', 'payment_method', 'fee_type'], 'dana_setting_scope_lookup_idx');
            $table->index(['gateway_merchant_code', 'environment', 'is_active'], 'dana_setting_merchant_env_active_idx');
            $table->index(['application_id', 'environment', 'is_active'], 'dana_setting_app_env_active_idx');
            $table->index(['environment', 'payment_method', 'fee_type', 'is_active'], 'dana_setting_env_method_active_idx');
            $table->unique(['scope_key', 'environment', 'payment_method', 'fee_type'], 'dana_setting_scope_env_method_type_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dana_payment_fee_settings');
    }
};
