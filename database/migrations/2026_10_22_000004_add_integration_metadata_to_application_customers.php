<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_customers', function (Blueprint $table): void {
            $table->string('owner_name')->nullable()->after('name');
            $table->string('source', 30)->default('manual')->after('phone_number');
            $table->timestamp('first_synced_at')->nullable()->after('source');
            $table->timestamp('last_synced_at')->nullable()->after('first_synced_at');
            $table->string('sync_status', 30)->default('manual')->after('last_synced_at');
            $table->string('sync_error_code', 100)->nullable()->after('sync_status');

            $table->index(['application_id', 'source', 'sync_status'], 'app_customer_integration_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('application_customers', function (Blueprint $table): void {
            $table->dropIndex('app_customer_integration_status_idx');
            $table->dropColumn([
                'owner_name',
                'source',
                'first_synced_at',
                'last_synced_at',
                'sync_status',
                'sync_error_code',
            ]);
        });
    }
};
