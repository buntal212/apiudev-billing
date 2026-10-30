<?php

namespace App\Services;

use RuntimeException;

class DokuSecretStore
{
    private const int NonceLength = 12; // 96 bits
    private const int TagLength = 16;   // 128 bits
    private const string Cipher = 'aes-256-gcm';

    /**
     * Mengenkripsi private key menggunakan AES-256-GCM dengan nonce acak 96-bit,
     * authentication tag 128-bit, dan verifikasi dekripsi in-memory sebelum disimpan.
     *
     * @return array{ciphertext: string, nonce: string, tag: string, key_version: string}
     */
    public function encryptPrivateKey(string $plaintextPrivateKey, string $merchantCode, string $keyVersion = 'v1'): array
    {
        $key = $this->deriveEncryptionKey($keyVersion);
        $nonce = random_bytes(self::NonceLength);
        $aad = $this->additionalAuthenticatedData($merchantCode, $keyVersion);

        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintextPrivateKey,
            self::Cipher,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad,
            self::TagLength
        );

        if ($ciphertext === false || strlen($tag) !== self::TagLength) {
            throw new RuntimeException('Enkripsi private key DOKU gagal.');
        }

        // Verifikasi in-memory sebelum mengembalikan payload
        $decrypted = $this->decryptPayload(
            base64_encode($ciphertext),
            base64_encode($nonce),
            base64_encode($tag),
            $merchantCode,
            $keyVersion
        );

        if ($decrypted !== $plaintextPrivateKey) {
            throw new RuntimeException('Verifikasi dekripsi in-memory private key DOKU tidak cocok.');
        }

        return [
            'ciphertext' => base64_encode($ciphertext),
            'nonce' => base64_encode($nonce),
            'tag' => base64_encode($tag),
            'key_version' => $keyVersion,
        ];
    }

    /**
     * Mendekripsi private key terenkripsi.
     */
    public function decryptPayload(
        string $base64Ciphertext,
        string $base64Nonce,
        string $base64Tag,
        string $merchantCode,
        string $keyVersion = 'v1'
    ): string {
        $ciphertext = base64_decode($base64Ciphertext, true);
        $nonce = base64_decode($base64Nonce, true);
        $tag = base64_decode($base64Tag, true);

        if ($ciphertext === false || $nonce === false || $tag === false) {
            throw new RuntimeException('Payload enkripsi private key DOKU rusak atau tidak valid.');
        }

        $key = $this->deriveEncryptionKey($keyVersion);
        $aad = $this->additionalAuthenticatedData($merchantCode, $keyVersion);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::Cipher,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad
        );

        if ($plaintext === false) {
            throw new RuntimeException('Dekripsi private key DOKU gagal. Authentication tag tidak cocok atau data telah dimodifikasi.');
        }

        return $plaintext;
    }

    private function additionalAuthenticatedData(string $merchantCode, string $keyVersion): string
    {
        return "doku-sandbox|merchant:{$merchantCode}|v:{$keyVersion}";
    }

    private function deriveEncryptionKey(string $keyVersion): string
    {
        $rawSecret = config('services.doku_gateway.secret_store_key')
            ?: config('services.dana_merchant_secret_store.keys.v1')
            ?: (string) config('app.key');

        if (str_starts_with($rawSecret, 'base64:')) {
            $rawSecret = base64_decode(substr($rawSecret, 7), true) ?: $rawSecret;
        }

        // Turunkan kunci 256-bit (32 bytes) menggunakan HKDF-SHA256
        return hash_hkdf('sha256', $rawSecret, 32, "doku-merchant-encryption-{$keyVersion}", 'doku-payment-salt');
    }
}
