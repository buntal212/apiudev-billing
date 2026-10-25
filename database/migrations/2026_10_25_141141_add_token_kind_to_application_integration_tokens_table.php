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
        Schema::table('application_integration_tokens', function (Blueprint $table): void {
            $table->string('token_kind', 20)->default('global')->after('name');
            $table->index(['application_id', 'token_kind', 'revoked_at'], 'app_integration_token_kind_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_integration_tokens', function (Blueprint $table): void {
            $table->dropIndex('app_integration_token_kind_idx');
            $table->dropColumn('token_kind');
        });
    }
};
