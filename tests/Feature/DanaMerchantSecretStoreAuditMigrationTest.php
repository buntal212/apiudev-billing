<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DanaMerchantSecretStoreAuditMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const string Table = 'dana_merchant_secret_store_audits';

    private const string ForeignKey = 'dmssa_secret_id_fk';

    private const string MerchantCreatedIndex = 'dana_secret_audit_merchant_created_idx';

    public function test_fresh_schema_has_the_short_named_secret_foreign_key_and_audit_index(): void
    {
        $foreignKey = $this->secretForeignKey();

        $this->assertNotNull($foreignKey);
        $this->assertSame(['dana_merchant_api_key_secret_id'], $foreignKey['columns']);
        $this->assertSame('dana_merchant_api_key_secrets', $foreignKey['foreign_table']);
        $this->assertSame(['id'], $foreignKey['foreign_columns']);
        $this->assertSame('set null', strtolower($foreignKey['on_delete']));
        $this->assertTrue(Schema::hasIndex(self::Table, self::MerchantCreatedIndex));
    }

    public function test_partial_audit_table_is_repaired_without_recreating_or_removing_it(): void
    {
        Schema::dropIfExists(self::Table);
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
        });

        $tableBeforeRepair = Schema::getColumnListing(self::Table);
        $this->migration()->up();
        $this->migration()->up();
        $foreignKey = $this->secretForeignKey();

        $this->assertSame($tableBeforeRepair, Schema::getColumnListing(self::Table));
        $this->assertNotNull($foreignKey);
        $this->assertSame(['dana_merchant_api_key_secret_id'], $foreignKey['columns']);
        $this->assertSame('dana_merchant_api_key_secrets', $foreignKey['foreign_table']);
        $this->assertSame(['id'], $foreignKey['foreign_columns']);
        $this->assertSame('set null', strtolower($foreignKey['on_delete']));
        $this->assertTrue(Schema::hasIndex(self::Table, self::MerchantCreatedIndex));
        $this->assertCount(1, array_filter(
            Schema::getForeignKeys(self::Table),
            fn (array $candidate): bool => $candidate['columns'] === ['dana_merchant_api_key_secret_id'],
        ));
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_16_090001_create_dana_merchant_secret_store_audits_table.php');
    }

    /** @return array{name: string, columns: list<string>, foreign_schema: ?string, foreign_table: string, foreign_columns: list<string>, on_update: string, on_delete: string}|null */
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
}
