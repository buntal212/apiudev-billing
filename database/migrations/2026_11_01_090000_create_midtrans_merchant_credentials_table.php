<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('midtrans_merchant_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('merchant_code', 60)->unique();
            $table->string('merchant_name', 100);
            $table->string('midtrans_merchant_id', 100)->nullable();
            $table->string('client_key', 150)->nullable();
            $table->string('environment', 20)->default('sandbox');
            $table->boolean('is_active')->default(true);

            // Encrypted Server Key (AES-256-GCM)
            $table->text('encrypted_server_key')->nullable();
            $table->string('server_key_nonce', 64)->nullable();
            $table->string('server_key_tag', 64)->nullable();

            // Encrypted Signature Secret Key (AES-256-GCM)
            $table->text('encrypted_signature_key')->nullable();
            $table->string('signature_key_nonce', 64)->nullable();
            $table->string('signature_key_tag', 64)->nullable();

            $table->string('key_version', 20)->default('v1');

            // Connection testing status
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 30)->nullable();
            $table->string('last_test_response_code', 20)->nullable();
            $table->text('last_test_message')->nullable();

            $table->timestamps();

            $table->index(['merchant_code', 'environment'], 'midtrans_cred_merchant_env_idx');
            $table->index(['environment', 'is_active'], 'midtrans_cred_env_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('midtrans_merchant_credentials');
    }
};
