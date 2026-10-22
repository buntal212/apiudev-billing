<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')
                ->constrained('invoices')
                ->restrictOnDelete();
            $table->foreignId('payment_attempt_id')
                ->constrained('payment_attempts')
                ->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->char('currency', 3);
            $table->timestamp('allocated_at');
            $table->string('idempotency_key', 150);
            $table->timestamps();

            $table->unique('invoice_id', 'payment_allocations_invoice_unique');
            $table->unique('payment_attempt_id', 'payment_allocations_attempt_unique');
            $table->unique('idempotency_key', 'payment_allocations_idempotency_unique');
            $table->index(['invoice_id', 'allocated_at'], 'payment_allocations_invoice_date_idx');
        });

        Schema::create('settlement_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')
                ->constrained('invoices')
                ->restrictOnDelete();
            $table->foreignId('payment_attempt_id')
                ->constrained('payment_attempts')
                ->restrictOnDelete();
            $table->string('event_type', 50);
            $table->string('status', 30);
            $table->string('idempotency_key', 150);
            $table->string('error_code', 100)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique('idempotency_key', 'settlement_events_idempotency_unique');
            $table->index(['invoice_id', 'status'], 'settlement_events_invoice_status_idx');
            $table->index(['payment_attempt_id', 'created_at'], 'settlement_events_attempt_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_events');
        Schema::dropIfExists('payment_allocations');
    }
};
