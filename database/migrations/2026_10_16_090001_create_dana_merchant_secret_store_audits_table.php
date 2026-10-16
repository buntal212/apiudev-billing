<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string Table = 'dana_merchant_secret_store_audits';

    private const string ForeignKey = 'dmssa_secret_id_fk';

    private const string MerchantCreatedIndex = 'dana_secret_audit_merchant_created_idx';

    public function up(): void
    {
        if (! Schema::hasTable(self::Table)) {
            Schema::create(self::Table, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('dana_merchant_api_key_secret_id')->nullable();
                $table->unsignedBigInteger('gateway_merchant_id');
                $table->string('merchant_code', 60);
                $table->string('environment', 20);
                $table->unsignedBigInteger('gateway_api_key_id');
                $table->string('operation', 60);
                $table->string('status', 30);
                $table->string('encryption_key_version', 60)->nullable();
                $table->json('safe_details')->nullable();
                $table->timestamps();

                $table->foreign('dana_merchant_api_key_secret_id', self::ForeignKey)
                    ->references('id')
                    ->on('dana_merchant_api_key_secrets')
                    ->nullOnDelete();
                $table->index(['gateway_merchant_id', 'environment', 'created_at'], self::MerchantCreatedIndex);
            });

            return;
        }

        if (! Schema::hasIndex(self::Table, self::MerchantCreatedIndex)) {
            Schema::table(self::Table, function (Blueprint $table): void {
                $table->index(['gateway_merchant_id', 'environment', 'created_at'], self::MerchantCreatedIndex);
            });
        }

        if (! $this->hasSecretForeignKey()) {
            Schema::table(self::Table, function (Blueprint $table): void {
                $table->foreign('dana_merchant_api_key_secret_id', self::ForeignKey)
                    ->references('id')
                    ->on('dana_merchant_api_key_secrets')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::Table)) {
            return;
        }

        $foreignKey = $this->secretForeignKey();
        if ($foreignKey !== null) {
            Schema::table(self::Table, function (Blueprint $table) use ($foreignKey): void {
                $table->dropForeign($foreignKey['name']);
            });
        }
    }

    private function hasSecretForeignKey(): bool
    {
        return $this->secretForeignKey() !== null;
    }

    /** @return array{name: string, columns: list<string>, foreign_table: string, foreign_columns: list<string>}|null */
    private function secretForeignKey(): ?array
    {
        foreach (Schema::getForeignKeys(self::Table) as $foreignKey) {
            if ($foreignKey['columns'] === ['dana_merchant_api_key_secret_id']
                && $foreignKey['foreign_table'] === 'dana_merchant_api_key_secrets'
                && $foreignKey['foreign_columns'] === ['id']) {
                return $foreignKey;
            }
        }

        return null;
    }
};
