<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->unsignedSmallInteger('duration_days')->nullable()->after('duration_months');
        });
        Schema::table('invoices', function (Blueprint $table): void {
            $table->unsignedSmallInteger('duration_days')->nullable()->after('duration_months');
        });

        DB::table('subscription_plans')->update([
            'duration_days' => DB::raw('duration_months * 30'),
        ]);
        DB::table('invoices')->update([
            'duration_days' => DB::raw('duration_months * 30'),
        ]);

        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->unsignedSmallInteger('duration_days')->nullable(false)->change();
        });
        Schema::table('invoices', function (Blueprint $table): void {
            $table->unsignedSmallInteger('duration_days')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('duration_days');
        });
        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->dropColumn('duration_days');
        });
    }
};
