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
}
