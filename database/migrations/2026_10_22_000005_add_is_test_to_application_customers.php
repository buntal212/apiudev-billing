<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_customers', function (Blueprint $table): void {
            $table->boolean('is_test')->default(false)->after('is_active');
            $table->index(['application_id', 'is_test'], 'app_customer_test_idx');
        });
    }

    public function down(): void
    {
        Schema::table('application_customers', function (Blueprint $table): void {
            $table->dropIndex('app_customer_test_idx');
            $table->dropColumn('is_test');
        });
    }
};
