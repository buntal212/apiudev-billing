<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dana_payment_method_fees', function (Blueprint $table): void {
            $table->id();
            $table->string('merchant_code', 60)->default('udev-central');
            $table->string('payment_method', 50);
            $table->string('payment_method_name', 100);
            $table->string('customer_fee_type', 20)->default('fixed'); // 'fixed' | 'percent'
            $table->decimal('customer_fee_rate', 10, 4)->default(0.0000); // 0 fee to customer
            $table->decimal('customer_fixed_amount', 15, 2)->default(0.00); // Rp0 default
            $table->string('merchant_fee_type', 20)->default('percent'); // 'percent' | 'fixed'
            $table->decimal('merchant_fee_rate', 10, 4)->default(0.7000); // e.g. 0.7000 for QRIS MDR, 1.5000 for DANA Wallet
            $table->decimal('merchant_fixed_amount', 15, 2)->default(0.00);
            $table->decimal('min_fee', 15, 2)->nullable();
            $table->decimal('max_fee', 15, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['merchant_code', 'payment_method'], 'dana_fee_merchant_method_uq');
            $table->index(['merchant_code', 'is_active'], 'dana_fee_merchant_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dana_payment_method_fees');
    }
};
