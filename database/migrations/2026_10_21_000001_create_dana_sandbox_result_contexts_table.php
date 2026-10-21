<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dana_sandbox_result_contexts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('gateway_merchant_id');
            $table->ulid('gateway_payment_id')->nullable();
            $table->char('context_hash', 64)->unique();
            $table->char('idempotency_fingerprint', 64);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['admin_user_id', 'gateway_merchant_id', 'idempotency_fingerprint'], 'dana_sandbox_result_context_idempotency_uq');
            $table->index(['gateway_merchant_id', 'gateway_payment_id'], 'dana_sandbox_result_context_payment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dana_sandbox_result_contexts');
    }
};
