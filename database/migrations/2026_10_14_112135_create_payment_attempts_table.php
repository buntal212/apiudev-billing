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
        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedSmallInteger('attempt_number');
            $table->string('provider', 50);
            $table->string('merchant_reference', 100)->nullable();
            $table->string('provider_reference', 100)->nullable();
            $table->decimal('amount', 15, 2);
            $table->char('currency', 3)->default('IDR');
            $table->string('status', 20)->default('initiated');
            $table->string('idempotency_key', 100);
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->text('failure_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('invoice_id', 'pay_invoice_fk')
                ->references('id')
                ->on('invoices')
                ->restrictOnDelete();

            $table->unique(['invoice_id', 'attempt_number'], 'pay_invoice_attempt_uq');
            $table->unique('idempotency_key', 'pay_idempotency_uq');
            $table->unique(['provider', 'provider_reference'], 'pay_provider_ref_uq');
            $table->index(['invoice_id', 'status'], 'pay_invoice_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
