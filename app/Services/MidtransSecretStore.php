<?php

namespace App\Services;

use RuntimeException;

class MidtransSecretStore
{
    private const int NonceLength = 12; // 96 bits

    private const int TagLength = 16;   // 128 bits

    private const string Cipher = 'aes-256-gcm';

    /**
     * Encrypt a secret (e.g. Server Key or Signature Key) using AES-256-GCM.
     * Includes in-memory decryption verification before returning.
     *
     * @return array{ciphertext: string, nonce: string, tag: string, key_version: string}
     */
    public function encryptSecret(
        string $plaintext,
        string $merchantCode,
        string $secretType = 'server_key',
        string $keyVersion = 'v1',
        string $environment = 'sandbox',
    ): array {
        $key = $this->deriveEncryptionKey($keyVersion);
        $nonce = random_bytes(self::NonceLength);
        $aad = $this->additionalAuthenticatedData($merchantCode, $secretType, $keyVersion, $environment);

        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::Cipher,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad,
            self::TagLength
        );

        if ($ciphertext === false || strlen($tag) !== self::TagLength) {
            throw new RuntimeException("Enkripsi {$secretType} Midtrans gagal.");
        }

        // Verify in-memory round trip
        $decrypted = $this->decryptSecret(
            base64_encode($ciphertext),
            base64_encode($nonce),
            base64_encode($tag),
            $merchantCode,
            $secretType,
            $keyVersion,
            $environment
        );

        if ($decrypted !== $plaintext) {
            throw new RuntimeException("Verifikasi dekripsi in-memory {$secretType} Midtrans tidak cocok.");
        }

        return [
            'ciphertext' => base64_encode($ciphertext),
            'nonce' => base64_encode($nonce),
            'tag' => base64_encode($tag),
            'key_version' => $keyVersion,
        ];
    }

    /**
     * Decrypt encrypted secret payload.
     * Supports both environment-specific AAD and legacy sandbox AAD for seamless backward compatibility.
     */
    public function decryptSecret(
        string $base64Ciphertext,
        string $base64Nonce,
        string $base64Tag,
        string $merchantCode,
        string $secretType = 'server_key',
        string $keyVersion = 'v1',
        string $environment = 'sandbox',
    ): string {
        $ciphertext = base64_decode($base64Ciphertext, true);
        $nonce = base64_decode($base64Nonce, true);
        $tag = base64_decode($base64Tag, true);

        if ($ciphertext === false || $nonce === false || $tag === false) {
            throw new RuntimeException("Payload enkripsi {$secretType} Midtrans rusak atau tidak valid.");
        }

        $key = $this->deriveEncryptionKey($keyVersion);
        $aad = $this->additionalAuthenticatedData($merchantCode, $secretType, $keyVersion, $environment);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::Cipher,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad
        );

        // Fallback to legacy sandbox AAD if decryption failed and environment was not sandbox
        if ($plaintext === false && $environment !== 'sandbox') {
            $legacyAad = $this->additionalAuthenticatedData($merchantCode, $secretType, $keyVersion, 'sandbox');
            $plaintext = openssl_decrypt(
                $ciphertext,
                self::Cipher,
                $key,
                OPENSSL_RAW_DATA,
                $nonce,
                $tag,
                $legacyAad
            );
        }

        if ($plaintext === false) {
            throw new RuntimeException("Dekripsi {$secretType} Midtrans gagal. Authentication tag tidak cocok.");
        }

        return $plaintext;
    }

    private function additionalAuthenticatedData(
        string $merchantCode,
        string $secretType,
        string $keyVersion,
        string $environment = 'sandbox',
    ): string {
        $env = strtolower(trim($environment)) === 'production' ? 'production' : 'sandbox';

        return "midtrans-{$env}|merchant:{$merchantCode}|type:{$secretType}|v:{$keyVersion}";
    }

    private function deriveEncryptionKey(string $keyVersion): string
    {
        $rawSecret = config('services.midtrans_gateway.secret_store_key')
            ?: config('services.dana_merchant_secret_store.keys.v1')
            ?: (string) config('app.key');

        if (str_starts_with($rawSecret, 'base64:')) {
            $rawSecret = base64_decode(substr($rawSecret, 7), true) ?: $rawSecret;
        }

        return hash_hkdf('sha256', $rawSecret, 32, "midtrans-merchant-encryption-{$keyVersion}", 'midtrans-payment-salt');
    }
}
