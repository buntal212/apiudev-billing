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
        Schema::create('dana_merchant_provisioning_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->constrained('users')->restrictOnDelete();
            $table->string('operation', 60);
            $table->string('merchant_code', 60);
            $table->unsignedBigInteger('gateway_merchant_id')->nullable();
            $table->string('environment', 20);
            $table->char('idempotency_fingerprint', 64);
            $table->string('status', 20);
            $table->unsignedSmallInteger('gateway_status')->nullable();
            $table->json('safe_details')->nullable();
            $table->timestamps();

            $table->index(['admin_user_id', 'created_at'], 'dana_merchant_audit_admin_created_idx');
            $table->index(['merchant_code', 'environment', 'created_at'], 'dana_merchant_audit_merchant_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dana_merchant_provisioning_audits');
    }
};
