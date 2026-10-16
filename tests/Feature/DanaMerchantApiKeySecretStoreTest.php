<?php

namespace Tests\Feature;

use App\Models\DanaMerchantApiKeySecret;
use App\Models\DanaMerchantProvisioningAudit;
use App\Models\DanaMerchantSecretStoreAudit;
use App\Services\DanaMerchantApiKeySecretStore;
use App\Services\DanaMerchantSecretStoreException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class DanaMerchantApiKeySecretStoreTest extends TestCase
{
    use RefreshDatabase;

    private const string PlaintextApiKey = 'dpk_test_only_never_a_real_credential';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureSecretStore('v1', str_repeat('a', 32));
    }

    public function test_store_encrypts_verifies_and_keeps_plaintext_out_of_models_audits_and_logs(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        Log::spy();

        $secret = $this->store()->store($this->attributes($merchant->gateway_merchant_id));

        $this->assertSame(DanaMerchantApiKeySecret::StatusActive, $secret->status);
        $this->assertSame(self::PlaintextApiKey, $this->store()->activeSecret($merchant->gateway_merchant_id, 'sandbox'));
        $this->assertStringNotContainsString(self::PlaintextApiKey, (string) $secret->getRawOriginal('ciphertext'));
        $this->assertArrayNotHasKey('ciphertext', $secret->toArray());
        $this->assertArrayNotHasKey('nonce', $secret->toArray());
        $this->assertArrayNotHasKey('authentication_tag', $secret->toArray());
        $this->assertArrayNotHasKey('key_fingerprint', $secret->toArray());

        $audit = DanaMerchantSecretStoreAudit::query()->sole();
        $auditDetails = json_encode($audit->safe_details, JSON_THROW_ON_ERROR);
        $this->assertSame('secret.stored', $audit->operation);
        $this->assertStringNotContainsString(self::PlaintextApiKey, $auditDetails);
        Log::shouldNotHaveReceived('debug');
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function test_store_rejects_missing_or_invalid_encryption_key_without_persisting_secret(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        config()->set('services.dana_merchant_secret_store.keys', []);

        $this->expectException(DanaMerchantSecretStoreException::class);
        $this->expectExceptionMessage('Konfigurasi Secret Store merchant belum tersedia.');

        try {
            $this->store()->store($this->attributes($merchant->gateway_merchant_id));
        } finally {
            $this->assertDatabaseCount('dana_merchant_api_key_secrets', 0);
        }
    }

    public function test_store_detects_wrong_or_corrupted_encryption_material_without_disclosing_plaintext(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        $secret = $this->store()->store($this->attributes($merchant->gateway_merchant_id));

        $this->configureSecretStore('v1', str_repeat('b', 32));
        try {
            $this->store()->activeSecret($merchant->gateway_merchant_id, 'sandbox');
            $this->fail('Dekripsi dengan kunci yang salah harus ditolak.');
        } catch (DanaMerchantSecretStoreException $exception) {
            $this->assertStringNotContainsString(self::PlaintextApiKey, $exception->getMessage());
        }

        $this->configureSecretStore('v1', str_repeat('a', 32));
        $secret->forceFill(['ciphertext' => base64_encode(random_bytes(48))])->save();

        $this->expectException(DanaMerchantSecretStoreException::class);
        $this->expectExceptionMessage('Integritas Secret API key merchant tidak valid.');
        $this->store()->activeSecret($merchant->gateway_merchant_id, 'sandbox');
    }

    public function test_store_rejects_non_provisioned_and_larissama_merchants(): void
    {
        try {
            $this->store()->store($this->attributes(999));
            $this->fail('Merchant tanpa provisioning harus ditolak.');
        } catch (DanaMerchantSecretStoreException $exception) {
            $this->assertSame('Merchant Sandbox UDEV tidak ditemukan.', $exception->getMessage());
        }

        $merchant = $this->sandboxProvisionedMerchant([
            'gateway_merchant_id' => 404,
            'merchant_code' => 'larissama',
        ]);

        $this->expectException(DanaMerchantSecretStoreException::class);
        $this->expectExceptionMessage('Merchant Sandbox UDEV tidak ditemukan.');
        $this->store()->store($this->attributes($merchant->gateway_merchant_id));
    }

    public function test_store_is_idempotent_and_refuses_a_different_key_for_same_gateway_key_identity(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        $attributes = $this->attributes($merchant->gateway_merchant_id);
        $stored = $this->store()->store($attributes);
        $replayed = $this->store()->store($attributes);

        $this->assertSame($stored->id, $replayed->id);
        $this->assertDatabaseCount('dana_merchant_api_key_secrets', 1);
        $this->assertDatabaseCount('dana_merchant_secret_store_audits', 2);

        $this->expectException(DanaMerchantSecretStoreException::class);
        $this->expectExceptionMessage('Identitas API key merchant sudah digunakan.');
        $this->store()->store([...$attributes, 'plaintext_api_key' => 'dpk_different_test_only_key']);
    }

    public function test_rotation_requires_explicit_confirmation_and_preserves_versioned_history(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        $first = $this->store()->store($this->attributes($merchant->gateway_merchant_id));
        $rotatedAttributes = $this->attributes($merchant->gateway_merchant_id, [
            'gateway_api_key_id' => 92,
            'key_prefix' => 'dpk_rotated',
            'plaintext_api_key' => 'dpk_rotated_test_only_key',
        ]);

        try {
            $this->store()->store($rotatedAttributes);
            $this->fail('Rotasi tanpa konfirmasi harus ditolak.');
        } catch (DanaMerchantSecretStoreException $exception) {
            $this->assertSame('Rotasi API key memerlukan konfirmasi eksplisit.', $exception->getMessage());
        }

        $rotated = $this->store()->store($rotatedAttributes, confirmRotation: true);

        $this->assertSame(1, $first->refresh()->key_version);
        $this->assertSame(DanaMerchantApiKeySecret::StatusRotationPending, $first->status);
        $this->assertSame(2, $rotated->key_version);
        $this->assertSame(DanaMerchantApiKeySecret::StatusActive, $rotated->status);
        $this->assertSame('dpk_rotated_test_only_key', $this->store()->activeSecret($merchant->gateway_merchant_id, 'sandbox'));
    }

    public function test_storage_transaction_failure_is_safely_wrapped_without_logging_or_recording_plaintext(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        Log::spy();
        DB::partialMock()
            ->shouldReceive('transaction')
            ->once()
            ->andThrow(new RuntimeException('database failed with '.self::PlaintextApiKey));

        try {
            $this->store()->store($this->attributes($merchant->gateway_merchant_id));
            $this->fail('Kegagalan transaksi harus diteruskan sebagai error aman.');
        } catch (DanaMerchantSecretStoreException $exception) {
            $this->assertSame('Secret API key merchant tidak dapat disimpan.', $exception->getMessage());
            $this->assertStringNotContainsString(self::PlaintextApiKey, $exception->getMessage());
        }

        Log::shouldNotHaveReceived('error');
    }

    public function test_secret_records_are_isolated_by_gateway_merchant(): void
    {
        $firstMerchant = $this->sandboxProvisionedMerchant(['gateway_merchant_id' => 101]);
        $secondMerchant = $this->sandboxProvisionedMerchant([
            'gateway_merchant_id' => 202,
            'merchant_code' => 'udev-second',
        ]);
        $this->store()->store($this->attributes($firstMerchant->gateway_merchant_id));

        $this->assertTrue($this->store()->hasActiveSecret($firstMerchant->gateway_merchant_id, 'sandbox'));
        $this->assertFalse($this->store()->hasActiveSecret($secondMerchant->gateway_merchant_id, 'sandbox'));

        $this->expectException(DanaMerchantSecretStoreException::class);
        $this->expectExceptionMessage('Secret API key merchant belum tersedia.');
        $this->store()->activeSecret($secondMerchant->gateway_merchant_id, 'sandbox');
    }

    private function store(): DanaMerchantApiKeySecretStore
    {
        return app(DanaMerchantApiKeySecretStore::class);
    }

    private function configureSecretStore(string $version, string $key): void
    {
        config()->set('services.dana_merchant_secret_store', [
            'active_key_version' => $version,
            'keys' => [$version => 'base64:'.base64_encode($key)],
        ]);
    }

    private function sandboxProvisionedMerchant(array $overrides = []): DanaMerchantProvisioningAudit
    {
        return DanaMerchantProvisioningAudit::factory()->create([
            'operation' => 'dana_merchant.provision',
            'gateway_merchant_id' => 101,
            'merchant_code' => 'udev-salon',
            'environment' => 'sandbox',
            'status' => 'succeeded',
            ...$overrides,
        ]);
    }

    /** @return array{gateway_merchant_id: int, gateway_api_key_id: int, key_prefix: string, plaintext_api_key: string} */
    private function attributes(int $gatewayMerchantId, array $overrides = []): array
    {
        return [
            'gateway_merchant_id' => $gatewayMerchantId,
            'gateway_api_key_id' => 91,
            'key_prefix' => 'dpk_test',
            'plaintext_api_key' => self::PlaintextApiKey,
            ...$overrides,
        ];
    }
}
