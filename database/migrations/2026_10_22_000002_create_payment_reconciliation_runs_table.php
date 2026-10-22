<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reconciliation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_attempt_id')
                ->constrained('payment_attempts')
                ->restrictOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('status', 30);
            $table->string('gateway_status', 30)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->timestamp('retry_after_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('idempotency_key', 150);
            $table->timestamps();

            $table->unique('idempotency_key', 'reconciliation_runs_idempotency_uq');
            $table->unique(['payment_attempt_id', 'attempt_number'], 'reconciliation_runs_attempt_number_uq');
            $table->index(['payment_attempt_id', 'created_at'], 'reconciliation_runs_attempt_date_idx');
            $table->index(['status', 'retry_after_at'], 'reconciliation_runs_status_retry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reconciliation_runs');
    }
};
