<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doku_gateway_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('merchant_code', 60);
            $table->string('environment', 20)->default('sandbox');
            $table->string('action', 60); // credentials.saved, token.tested, status.viewed, etc.
            $table->string('status', 20); // success, failed
            $table->string('response_code', 20)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('metadata')->nullable(); // strictly sanitized without secrets
            $table->timestamp('created_at')->useCurrent();

            $table->index(['merchant_code', 'created_at'], 'doku_audits_merchant_date_idx');
            $table->index(['action', 'created_at'], 'doku_audits_action_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doku_gateway_audits');
    }
};
