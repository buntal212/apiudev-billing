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
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('invoice_number', 50);
            $table->unsignedBigInteger('application_customer_id');
            $table->unsignedBigInteger('subscription_plan_id');
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->decimal('amount', 15, 2);
            $table->char('currency', 3)->default('IDR');
            $table->unsignedSmallInteger('duration_months');
            $table->string('idempotency_key', 100);
            $table->string('status', 20)->default('pending');
            $table->timestamp('issued_at');
            $table->timestamp('due_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('application_customer_id', 'inv_customer_fk')
                ->references('id')
                ->on('application_customers')
                ->restrictOnDelete();
            $table->foreign('subscription_plan_id', 'inv_plan_fk')
                ->references('id')
                ->on('subscription_plans')
                ->restrictOnDelete();
            $table->foreign('subscription_id', 'inv_subscription_fk')
                ->references('id')
                ->on('subscriptions')
                ->restrictOnDelete();

            $table->unique('invoice_number', 'inv_number_uq');
            $table->unique('idempotency_key', 'inv_idempotency_uq');
            $table->index('application_customer_id', 'inv_customer_idx');
            $table->index('status', 'inv_status_idx');
            $table->index('due_at', 'inv_due_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
