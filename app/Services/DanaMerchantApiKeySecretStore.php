<?php

namespace App\Services;

use App\Models\DanaMerchantApiKeySecret;
use App\Models\DanaMerchantProvisioningAudit;
use App\Models\DanaMerchantSecretStoreAudit;
use Illuminate\Support\Facades\DB;
use Throwable;

class DanaMerchantApiKeySecretStore
{
    private const int NonceLength = 12;

    private const int AuthenticationTagLength = 16;

    private const string Cipher = 'aes-256-gcm';

    /**
     * @param  array{gateway_merchant_id: int, gateway_api_key_id: int, key_prefix: string, plaintext_api_key: string}  $attributes
     */
    public function store(array $attributes, bool $confirmRotation = false): DanaMerchantApiKeySecret
    {
        $merchant = $this->sandboxProvisionedMerchant($attributes['gateway_merchant_id']);
        $this->assertStoreableAttributes($attributes);
        $encryptionKeyVersion = $this->activeEncryptionKeyVersion();
        $key = $this->keyForVersion($encryptionKeyVersion);
        $fingerprint = $this->fingerprint($attributes['plaintext_api_key'], $key);

        try {
            return DB::transaction(function () use ($attributes, $merchant, $confirmRotation, $encryptionKeyVersion, $key, $fingerprint): DanaMerchantApiKeySecret {
                $existing = DanaMerchantApiKeySecret::query()
                    ->where('gateway_merchant_id', $attributes['gateway_merchant_id'])
                    ->where('environment', 'sandbox')
                    ->where('gateway_api_key_id', $attributes['gateway_api_key_id'])
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    if ($existing->key_fingerprint !== null && hash_equals($existing->key_fingerprint, $fingerprint)) {
                        $this->recordAudit($existing, 'secret.store_replayed', 'succeeded', ['storage_verified' => true]);

                        return $existing;
                    }

                    throw new DanaMerchantSecretStoreException('Identitas API key merchant sudah digunakan.');
                }

                $secrets = DanaMerchantApiKeySecret::query()
                    ->where('gateway_merchant_id', $attributes['gateway_merchant_id'])
                    ->where('environment', 'sandbox')
                    ->lockForUpdate()
                    ->get();
                $activeSecret = $secrets->firstWhere('status', DanaMerchantApiKeySecret::StatusActive);

                if ($activeSecret !== null && ! $confirmRotation) {
                    throw new DanaMerchantSecretStoreException('Rotasi API key memerlukan konfirmasi eksplisit.');
                }

                $keyVersionNumber = ((int) $secrets->max('key_version')) + 1;
                $additionalAuthenticatedData = $this->additionalAuthenticatedData(
                    $attributes['gateway_merchant_id'],
                    $attributes['gateway_api_key_id'],
                    $encryptionKeyVersion,
                    $keyVersionNumber,
                );
                $encrypted = $this->encrypt($attributes['plaintext_api_key'], $key, $additionalAuthenticatedData);
                $this->decryptPayload(
                    ciphertext: $encrypted['ciphertext'],
                    nonce: $encrypted['nonce'],
                    authenticationTag: $encrypted['authentication_tag'],
                    key: $key,
                    additionalAuthenticatedData: $additionalAuthenticatedData,
                );
                $secret = DanaMerchantApiKeySecret::query()->create([
                    'gateway_merchant_id' => $attributes['gateway_merchant_id'],
                    'merchant_code' => $merchant->merchant_code,
                    'environment' => 'sandbox',
                    'gateway_api_key_id' => $attributes['gateway_api_key_id'],
                    'key_prefix' => $attributes['key_prefix'],
                    'key_version' => $keyVersionNumber,
                    'encryption_key_version' => $encryptionKeyVersion,
                    'status' => DanaMerchantApiKeySecret::StatusPending,
                    'ciphertext' => $encrypted['ciphertext'],
                    'nonce' => $encrypted['nonce'],
                    'authentication_tag' => $encrypted['authentication_tag'],
                    'key_fingerprint' => $fingerprint,
                ]);

                if ($activeSecret !== null) {
                    $activeSecret->update(['status' => DanaMerchantApiKeySecret::StatusRotationPending]);
                }

                $secret->update(['status' => DanaMerchantApiKeySecret::StatusActive]);
                $this->recordAudit($secret, $activeSecret === null ? 'secret.stored' : 'secret.rotated', 'succeeded', [
                    'storage_verified' => true,
                    'rotation_confirmed' => $activeSecret !== null,
                ]);

                return $secret;
            });
        } catch (DanaMerchantSecretStoreException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DanaMerchantSecretStoreException('Secret API key merchant tidak dapat disimpan.', previous: $exception);
        }
    }

    public function hasActiveSecret(int $gatewayMerchantId, string $environment): bool
    {
        return DanaMerchantApiKeySecret::query()
            ->where('gateway_merchant_id', $gatewayMerchantId)
            ->where('environment', $environment)
            ->where('status', DanaMerchantApiKeySecret::StatusActive)
            ->exists();
    }

    public function activeSecret(int $gatewayMerchantId, string $environment): string
    {
        $secret = DanaMerchantApiKeySecret::query()
            ->where('gateway_merchant_id', $gatewayMerchantId)
            ->where('environment', $environment)
            ->where('status', DanaMerchantApiKeySecret::StatusActive)
            ->first();

        if ($secret === null) {
            throw new DanaMerchantSecretStoreException('Secret API key merchant belum tersedia.');
        }

        if (! is_string($secret->ciphertext)
            || ! is_string($secret->nonce)
            || ! is_string($secret->authentication_tag)
            || ! is_string($secret->encryption_key_version)) {
            throw new DanaMerchantSecretStoreException('Secret API key merchant tidak dapat digunakan.');
        }

        return $this->decryptPayload(
            ciphertext: $secret->ciphertext,
            nonce: $secret->nonce,
            authenticationTag: $secret->authentication_tag,
            key: $this->keyForVersion($secret->encryption_key_version),
            additionalAuthenticatedData: $this->additionalAuthenticatedData(
                $secret->gateway_merchant_id,
                $secret->gateway_api_key_id,
                $secret->encryption_key_version,
                $secret->key_version,
            ),
        );
    }

    public function markRevoked(int $gatewayMerchantId, int $gatewayApiKeyId): void
    {
        DB::transaction(function () use ($gatewayMerchantId, $gatewayApiKeyId): void {
            $secret = DanaMerchantApiKeySecret::query()
                ->where('gateway_merchant_id', $gatewayMerchantId)
                ->where('environment', 'sandbox')
                ->where('gateway_api_key_id', $gatewayApiKeyId)
                ->lockForUpdate()
                ->first();

            if ($secret === null) {
                throw new DanaMerchantSecretStoreException('Secret API key merchant tidak ditemukan.');
            }

            $secret->update(['status' => DanaMerchantApiKeySecret::StatusRevoked]);
            $this->recordAudit($secret, 'secret.revoked', 'succeeded', []);
        });
    }

    /**
     * @param  array{gateway_merchant_id: int, gateway_api_key_id: int, key_prefix: string, plaintext_api_key: string}  $attributes
     */
    private function assertStoreableAttributes(array $attributes): void
    {
        if ($attributes['gateway_api_key_id'] <= 0
            || trim($attributes['key_prefix']) === ''
            || trim($attributes['plaintext_api_key']) === '') {
            throw new DanaMerchantSecretStoreException('Metadata API key merchant tidak valid.');
        }
    }

    private function sandboxProvisionedMerchant(int $gatewayMerchantId): DanaMerchantProvisioningAudit
    {
        $merchant = DanaMerchantProvisioningAudit::query()
            ->where('operation', 'dana_merchant.provision')
            ->where('gateway_merchant_id', $gatewayMerchantId)
            ->where('environment', 'sandbox')
            ->where('status', 'succeeded')
            ->where('merchant_code', '!=', 'larissama')
            ->latest('id')
            ->first();

        if ($merchant === null) {
            throw new DanaMerchantSecretStoreException('Merchant Sandbox UDEV tidak ditemukan.');
        }

        return $merchant;
    }

    private function activeEncryptionKeyVersion(): string
    {
        $version = config('services.dana_merchant_secret_store.active_key_version');

        if (! is_string($version) || $version === '') {
            throw new DanaMerchantSecretStoreException('Konfigurasi Secret Store merchant belum tersedia.');
        }

        return $version;
    }

    private function keyForVersion(string $version): string
    {
        $keys = config('services.dana_merchant_secret_store.keys', []);
        $encodedKey = is_array($keys) ? ($keys[$version] ?? null) : null;

        if (! is_string($encodedKey) || ! str_starts_with($encodedKey, 'base64:')) {
            throw new DanaMerchantSecretStoreException('Konfigurasi Secret Store merchant belum tersedia.');
        }

        $key = base64_decode(substr($encodedKey, 7), true);
        if (! is_string($key) || strlen($key) !== 32) {
            throw new DanaMerchantSecretStoreException('Konfigurasi Secret Store merchant tidak valid.');
        }

        return $key;
    }

    /**
     * @return array{ciphertext: string, nonce: string, authentication_tag: string}
     */
    private function encrypt(string $plaintext, string $key, string $additionalAuthenticatedData): array
    {
        $nonce = random_bytes(self::NonceLength);
        $authenticationTag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::Cipher,
            $this->encryptionKey($key),
            OPENSSL_RAW_DATA,
            $nonce,
            $authenticationTag,
            $additionalAuthenticatedData,
            self::AuthenticationTagLength,
        );

        if (! is_string($ciphertext) || strlen($authenticationTag) !== self::AuthenticationTagLength) {
            throw new DanaMerchantSecretStoreException('Secret API key merchant tidak dapat disimpan.');
        }

        return [
            'ciphertext' => base64_encode($ciphertext),
            'nonce' => $nonce,
            'authentication_tag' => $authenticationTag,
        ];
    }

    private function decryptPayload(string $ciphertext, string $nonce, string $authenticationTag, string $key, string $additionalAuthenticatedData): string
    {
        $decodedCiphertext = base64_decode($ciphertext, true);
        if (! is_string($decodedCiphertext)
            || strlen($nonce) !== self::NonceLength
            || strlen($authenticationTag) !== self::AuthenticationTagLength) {
            throw new DanaMerchantSecretStoreException('Integritas Secret API key merchant tidak valid.');
        }

        $plaintext = openssl_decrypt(
            $decodedCiphertext,
            self::Cipher,
            $this->encryptionKey($key),
            OPENSSL_RAW_DATA,
            $nonce,
            $authenticationTag,
            $additionalAuthenticatedData,
        );

        if (! is_string($plaintext) || $plaintext === '') {
            throw new DanaMerchantSecretStoreException('Integritas Secret API key merchant tidak valid.');
        }

        return $plaintext;
    }

    private function encryptionKey(string $key): string
    {
        return hash_hkdf('sha256', $key, 32, 'dana-merchant-secret-encryption');
    }

    private function fingerprint(string $plaintext, string $key): string
    {
        return hash_hmac('sha256', $plaintext, hash_hkdf('sha256', $key, 32, 'dana-merchant-secret-fingerprint'));
    }

    private function additionalAuthenticatedData(int $gatewayMerchantId, int $gatewayApiKeyId, string $keyVersion, int $secretVersion): string
    {
        return implode('|', ['dana-merchant-api-key', 'sandbox', $gatewayMerchantId, $gatewayApiKeyId, $keyVersion, $secretVersion]);
    }

    /** @param array<string, bool|int|string> $safeDetails */
    private function recordAudit(DanaMerchantApiKeySecret $secret, string $operation, string $status, array $safeDetails): void
    {
        DanaMerchantSecretStoreAudit::query()->create([
            'dana_merchant_api_key_secret_id' => $secret->getKey(),
            'gateway_merchant_id' => $secret->gateway_merchant_id,
            'merchant_code' => $secret->merchant_code,
            'environment' => $secret->environment,
            'gateway_api_key_id' => $secret->gateway_api_key_id,
            'operation' => $operation,
            'status' => $status,
            'encryption_key_version' => $secret->encryption_key_version,
            'safe_details' => $safeDetails,
        ]);
    }
}
