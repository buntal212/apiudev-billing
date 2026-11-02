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
        Schema::table('payment_attempts', function (Blueprint $table): void {
            // Widen gateway_payment_id from CHAR(26) (ULID) to VARCHAR(100)
            // to safely store Midtrans transaction UUIDs (36 characters) and arbitrary gateway references
            // without truncation or losing existing identifiers.
            $table->string('gateway_payment_id', 100)->nullable()->change();

            // Widen checkout_url from TEXT (64KB) to MEDIUMTEXT (16MB)
            // so encrypted URLs/payloads never exceed column capacity.
            $table->mediumText('checkout_url')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->ulid('gateway_payment_id')->nullable()->change();
            $table->text('checkout_url')->nullable()->change();
        });
    }
};
