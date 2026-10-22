<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_integration_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->restrictOnDelete();
            $table->string('name', 100);
            $table->char('token_hash', 64)->unique();
            $table->string('token_prefix', 16);
            $table->json('scopes');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['application_id', 'revoked_at', 'expires_at'], 'app_integration_token_active_idx');
        });

        Schema::create('application_integration_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->foreignId('application_integration_token_id')->nullable()->constrained('application_integration_tokens')->nullOnDelete();
            $table->string('external_customer_id', 100)->nullable();
            $table->string('operation', 80);
            $table->string('status', 30);
            $table->string('error_code', 100)->nullable();
            $table->string('request_id', 100)->nullable();
            $table->json('safe_details')->nullable();
            $table->timestamps();

            $table->index(['application_id', 'created_at'], 'app_integration_audit_application_created_idx');
            $table->index(['application_integration_token_id', 'created_at'], 'app_integration_audit_token_created_idx');
            $table->index(['external_customer_id', 'created_at'], 'app_integration_audit_external_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_integration_audits');
        Schema::dropIfExists('application_integration_tokens');
    }
};
