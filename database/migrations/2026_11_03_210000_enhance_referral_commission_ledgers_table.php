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
        Schema::table('referral_commission_ledgers', function (Blueprint $table): void {
            $table->unsignedBigInteger('payment_allocation_id')->nullable()->change();
            $table->foreignId('reversal_of_ledger_id')->nullable()->after('payment_allocation_id')->constrained('referral_commission_ledgers')->nullOnDelete();
            $table->string('status', 20)->default('available')->after('amount');
            $table->string('idempotency_key', 100)->nullable()->unique()->after('status');
            $table->text('notes')->nullable()->after('idempotency_key');

            $table->index(['customer_referral_id', 'status']);
            $table->index(['partner_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('referral_commission_ledgers', function (Blueprint $table): void {
            $table->dropIndex(['customer_referral_id', 'status']);
            $table->dropIndex(['partner_id', 'status']);
            $table->dropUnique(['idempotency_key']);
            $table->dropConstrainedForeignId('reversal_of_ledger_id');
            $table->dropColumn(['status', 'idempotency_key', 'notes']);
            $table->unsignedBigInteger('payment_allocation_id')->nullable(false)->change();
        });
    }
};
