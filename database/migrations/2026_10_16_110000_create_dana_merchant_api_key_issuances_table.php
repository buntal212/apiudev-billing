<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dana_merchant_api_key_issuances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('gateway_merchant_id');
            $table->string('merchant_code', 60);
            $table->string('environment', 20);
            $table->string('idempotency_key', 128);
            $table->char('request_fingerprint', 64);
            $table->unsignedBigInteger('gateway_issuance_id')->nullable();
            $table->unsignedBigInteger('gateway_api_key_id')->nullable();
            $table->string('key_prefix', 100)->nullable();
            $table->string('status', 30);
            $table->timestamp('secret_stored_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['gateway_merchant_id', 'idempotency_key'], 'dana_key_issue_merchant_idem_uq');
            $table->index(['gateway_merchant_id', 'status'], 'dana_key_issue_merchant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dana_merchant_api_key_issuances');
    }
};
