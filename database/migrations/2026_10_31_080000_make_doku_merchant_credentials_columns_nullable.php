<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('doku_merchant_credentials')) {
            Schema::table('doku_merchant_credentials', function (Blueprint $table): void {
                $table->string('client_id', 100)->nullable()->change();
                $table->text('encrypted_private_key')->nullable()->change();
                $table->string('key_nonce', 64)->nullable()->change();
                $table->string('key_tag', 64)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('doku_merchant_credentials')) {
            Schema::table('doku_merchant_credentials', function (Blueprint $table): void {
                $table->string('client_id', 100)->nullable(false)->change();
                $table->text('encrypted_private_key')->nullable(false)->change();
                $table->string('key_nonce', 64)->nullable(false)->change();
                $table->string('key_tag', 64)->nullable(false)->change();
            });
        }
    }
};
