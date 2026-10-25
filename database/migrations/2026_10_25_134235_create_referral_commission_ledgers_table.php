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
        Schema::create('referral_commission_ledgers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('partner_id')->constrained()->restrictOnDelete();
            $table->foreignId('referral_code_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_referral_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_allocation_id')->constrained()->restrictOnDelete();
            $table->string('commission_type', 20);
            $table->decimal('amount', 15, 2);
            $table->timestamp('verified_at');
            $table->timestamps();

            $table->unique('payment_allocation_id');
            $table->index(['partner_id', 'verified_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('referral_commission_ledgers');
    }
};
