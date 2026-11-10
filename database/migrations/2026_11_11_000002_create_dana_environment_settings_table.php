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
        if (! Schema::hasTable('dana_environment_settings')) {
            Schema::create('dana_environment_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('gateway', 30)->default('dana')->unique();
                $table->string('active_environment', 20)->default('sandbox');
                $table->boolean('is_production_ready')->default(false);
                $table->timestamp('production_activated_at')->nullable();
                $table->foreignId('production_activated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('last_switched_at')->nullable();
                $table->foreignId('last_switched_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('activation_notes')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dana_environment_settings');
    }
};
