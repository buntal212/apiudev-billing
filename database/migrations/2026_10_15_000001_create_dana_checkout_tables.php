<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_payment_gateways', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->string('provider', 30)->default('dana');
            $table->string('environment', 20)->default('sandbox');
            $table->string('gateway_merchant_code', 60);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['application_id', 'provider', 'environment'], 'app_gateway_provider_env_unique');
            $table->index(['provider', 'environment', 'is_active'], 'app_gateway_active_idx');
        });

        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->string('gateway_merchant_code', 60)->nullable()->after('merchant_reference');
            $table->string('gateway_environment', 20)->nullable()->after('gateway_merchant_code');
            $table->ulid('gateway_payment_id')->nullable()->after('provider_reference');
            $table->text('checkout_url')->nullable()->after('gateway_payment_id');
            $table->timestamp('checkout_expires_at')->nullable()->after('checkout_url');
            $table->unique(['provider', 'gateway_payment_id'], 'pay_provider_gateway_payment_uq');
        });

        Schema::create('checkout_outbox_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_attempt_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 100)->unique();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamps();

            $table->unique('payment_attempt_id', 'checkout_outbox_attempt_unique');
            $table->index(['status', 'available_at'], 'checkout_outbox_dispatch_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_outbox_messages');

        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropUnique('pay_provider_gateway_payment_uq');
            $table->dropColumn([
                'gateway_merchant_code',
                'gateway_environment',
                'gateway_payment_id',
                'checkout_url',
                'checkout_expires_at',
            ]);
        });

        Schema::dropIfExists('application_payment_gateways');
    }
};
