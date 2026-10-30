<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doku_merchant_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('merchant_code', 60)->unique();
            $table->string('merchant_name', 100);
            $table->string('client_id', 100);
            $table->string('environment', 20)->default('sandbox');
            $table->boolean('is_active')->default(true);

            // Encrypted Private Key (AES-256-GCM)
            $table->text('encrypted_private_key');
            $table->string('key_nonce', 64);
            $table->string('key_tag', 64);
            $table->string('key_version', 20)->default('v1');

            // Public Key & Fingerprint
            $table->text('public_key')->nullable();
            $table->string('public_key_fingerprint', 64)->nullable();

            // Last test status
            $table->timestamp('last_token_tested_at')->nullable();
            $table->string('last_token_test_status', 30)->nullable();
            $table->string('last_token_test_response_code', 20)->nullable();
            $table->text('last_token_test_message')->nullable();

            $table->timestamps();

            $table->index(['merchant_code', 'environment'], 'doku_cred_merchant_env_idx');
            $table->index(['environment', 'is_active'], 'doku_cred_env_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doku_merchant_credentials');
    }
};
