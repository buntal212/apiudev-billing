<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dana_merchant_api_key_secrets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('gateway_merchant_id');
            $table->string('merchant_code', 60);
            $table->string('environment', 20);
            $table->unsignedBigInteger('gateway_api_key_id');
            $table->string('key_prefix', 100);
            $table->unsignedSmallInteger('key_version');
            $table->string('encryption_key_version', 60);
            $table->string('status', 30);
            $table->longText('ciphertext')->nullable();
            $table->binary('nonce')->nullable();
            $table->binary('authentication_tag')->nullable();
            $table->char('key_fingerprint', 64)->nullable();
            $table->timestamps();

            $table->unique(['gateway_merchant_id', 'environment', 'gateway_api_key_id'], 'dana_secret_gateway_key_unique');
            $table->unique(['gateway_merchant_id', 'environment', 'key_version'], 'dana_secret_merchant_version_unique');
            $table->index(['gateway_merchant_id', 'environment', 'status'], 'dana_secret_merchant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dana_merchant_api_key_secrets');
    }
};
