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
        Schema::create('customer_referrals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->foreignId('application_customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('partner_id')->constrained()->restrictOnDelete();
            $table->foreignId('referral_code_id')->constrained()->restrictOnDelete();
            $table->string('referral_code', 40);
            $table->string('referral_type', 20);
            $table->decimal('base_price', 15, 2);
            $table->decimal('discount_amount', 15, 2);
            $table->decimal('customer_price', 15, 2);
            $table->timestamp('locked_at');
            $table->timestamps();

            $table->unique('application_customer_id');
            $table->index(['application_id', 'referral_code_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_referrals');
    }
};
