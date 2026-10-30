<?php

namespace App\Services;

use RuntimeException;

class DokuSnapSignatureService
{
    public function formatStringToSign(string $clientKey, string $timestamp): string
    {
        return $clientKey . '|' . $timestamp;
    }

    public function signB2b(string $clientKey, string $timestamp, string $privateKey): string
    {
        $normalizedKey = $this->normalizePrivateKey($privateKey);
        $key = @openssl_pkey_get_private($normalizedKey);

        if ($key === false) {
            throw new RuntimeException('Private key RSA tidak dapat dimuat.');
        }

        $stringToSign = $this->formatStringToSign($clientKey, $timestamp);
        $signed = @openssl_sign($stringToSign, $rawSignature, $key, OPENSSL_ALGO_SHA256);

        if ($signed !== true) {
            throw new RuntimeException('Pembuatan signature SNAP B2B RSA-SHA256 gagal.');
        }

        return base64_encode($rawSignature);
    }

    public function extractPublicKey(string $privateKey): ?string
    {
        $normalizedKey = $this->normalizePrivateKey($privateKey);
        $key = @openssl_pkey_get_private($normalizedKey);

        if ($key === false) {
            return null;
        }

        $details = openssl_pkey_get_details($key);

        return is_array($details) && isset($details['key']) ? (string) $details['key'] : null;
    }

    public function publicKeyFingerprint(string $publicKey): ?string
    {
        $clean = preg_replace('/-----(?:BEGIN|END) PUBLIC KEY-----|\s+/', '', $publicKey);
        if (! is_string($clean) || $clean === '') {
            return null;
        }

        $der = base64_decode($clean, true);

        return $der === false ? null : hash('sha256', $der);
    }

    public function normalizePrivateKey(string $key): string
    {
        $key = trim($key);
        if (str_contains($key, '-----BEGIN')) {
            return $key;
        }

        return "-----BEGIN RSA PRIVATE KEY-----\n" . wordwrap($key, 64, "\n", true) . "\n-----END RSA PRIVATE KEY-----";
    }

    /**
     * Menghasilkan pasangan RSA Key Pair 2048-bit baru dan memvalidasi kecocokan kuncinya.
     *
     * @return array{private_key: string, public_key: string, fingerprint: string, bits: int}
     */
    public function generateRsaKeyPair(int $bits = 2048): array
    {
        $cnf = $this->resolveOpenSslConfigPath();
        $config = [
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        if ($cnf !== null) {
            $config['config'] = $cnf;
        }

        $res = @openssl_pkey_new($config);
        if ($res === false) {
            $res = @openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        }

        if ($res === false) {
            throw new RuntimeException('Gagal membuat pasangan RSA key pair menggunakan OpenSSL: ' . openssl_error_string());
        }

        $privateKey = '';
        $exportConfig = $cnf !== null ? ['config' => $cnf] : [];
        if (! @openssl_pkey_export($res, $privateKey, null, $exportConfig)) {
            throw new RuntimeException('Gagal mengekspor RSA private key: ' . openssl_error_string());
        }

        $details = openssl_pkey_get_details($res);
        if (! is_array($details) || empty($details['key'])) {
            throw new RuntimeException('Gagal mengekspor RSA public key dari key pair.');
        }

        $publicKey = (string) $details['key'];
        $fingerprint = $this->publicKeyFingerprint($publicKey);

        if (! is_string($fingerprint) || $fingerprint === '') {
            throw new RuntimeException('Gagal menghitung SHA-256 fingerprint dari public key.');
        }

        // Validasi kecocokan key pair dengan signing challenge
        $this->validateKeyPair($privateKey, $publicKey);

        return [
            'private_key' => $privateKey,
            'public_key' => $publicKey,
            'fingerprint' => $fingerprint,
            'bits' => $bits,
        ];
    }

    /**
     * Memvalidasi bahwa RSA private key dan public key merupakan pasangan yang cocok
     * melalui penandatanganan dan verifikasi challenge acak.
     */
    public function validateKeyPair(string $privateKey, string $publicKey): bool
    {
        $challenge = 'doku-key-validation-' . bin2hex(random_bytes(16));
        $signature = '';

        $privKeyResource = @openssl_pkey_get_private($this->normalizePrivateKey($privateKey));
        if ($privKeyResource === false) {
            throw new RuntimeException('Private key tidak valid untuk validasi pasangan.');
        }

        if (! @openssl_sign($challenge, $signature, $privKeyResource, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Gagal melakukan uji penandatanganan RSA.');
        }

        $pubKeyResource = @openssl_pkey_get_public($publicKey);
        if ($pubKeyResource === false) {
            throw new RuntimeException('Public key tidak valid untuk validasi pasangan.');
        }

        $verify = @openssl_verify($challenge, $signature, $pubKeyResource, OPENSSL_ALGO_SHA256);
        if ($verify !== 1) {
            throw new RuntimeException('Validasi pasangan kunci gagal: public key tidak cocok dengan private key.');
        }

        return true;
    }

    /**
     * Menemukan path konfigurasi openssl.cnf yang tersedia pada sistem (Windows/Linux/aaPanel).
     */
    public function resolveOpenSslConfigPath(): ?string
    {
        $custom = getenv('OPENSSL_CONF');
        if ($custom && @file_exists($custom)) {
            return $custom;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $candidates = [
                'C:/ServBay/packages/php/8.3/extras/ssl/openssl.cnf',
                'C:/Program Files/Common Files/SSL/openssl.cnf',
                'C:/OpenSSL-Win64/bin/openssl.cnf',
                'C:/OpenSSL-Win32/bin/openssl.cnf',
            ];
            if ($cafile = ini_get('openssl.cafile')) {
                $candidates[] = dirname($cafile) . '/openssl.cnf';
            }
        } else {
            $candidates = [
                '/www/server/php/83/etc/openssl.cnf',
                '/etc/ssl/openssl.cnf',
                '/usr/lib/ssl/openssl.cnf',
                '/etc/pki/tls/openssl.cnf',
            ];
        }

        foreach ($candidates as $candidate) {
            if ($candidate && @file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
