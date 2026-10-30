<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doku_merchant_key_backups', function (Blueprint $table): void {
            $table->id();
            $table->string('merchant_code', 60);
            $table->string('environment', 20)->default('sandbox');
            $table->text('encrypted_private_key');
            $table->string('key_nonce', 64);
            $table->string('key_tag', 64);
            $table->string('key_version', 20)->default('v1');
            $table->text('public_key');
            $table->string('public_key_fingerprint', 64);
            $table->timestamp('rotated_at')->useCurrent();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 50)->default('rotation');
            $table->timestamps();

            $table->index(['merchant_code', 'environment', 'rotated_at'], 'doku_key_backups_merchant_env_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doku_merchant_key_backups');
    }
};
