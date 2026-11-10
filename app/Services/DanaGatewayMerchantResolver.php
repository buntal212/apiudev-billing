<?php

namespace App\Services;

use RuntimeException;

class DanaGatewayMerchantResolver
{
    private DanaMerchantApiKeySecretStore $secretStore;

    public function __construct(
        private readonly DanaGatewayMerchantCatalog $merchantCatalog,
        ?DanaMerchantApiKeySecretStore $secretStore = null,
    ) {
        $this->secretStore = $secretStore ?? app(DanaMerchantApiKeySecretStore::class);
    }

    public function resolve(string $merchantCode, string $environment): DanaGatewayMerchant
    {
        $this->merchantCatalog->ensureUsable($merchantCode, $environment);

        $baseUrl = config("services.dana_gateway.base_urls.{$environment}");

        // 1. Priority A: Encrypted secret store from database (AES-256-GCM)
        $apiKey = null;
        try {
            $apiKey = $this->secretStore->activeSecretForMerchantCode($merchantCode, $environment);
        } catch (\Throwable) {
            $apiKey = null;
        }

        // 2. Priority B: Legacy .env merchant_api_keys for transition compatibility
        if (! is_string($apiKey) || $apiKey === '') {
            $keys = config('services.dana_gateway.merchant_api_keys', []);
            $apiKey = is_array($keys) && is_array($keys[$environment] ?? null)
                ? $keys[$environment][$merchantCode] ?? null
                : null;
        }

        if (! is_string($baseUrl) || $baseUrl === '' || ! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('Konfigurasi merchant gateway DANA belum lengkap.');
        }

        return new DanaGatewayMerchant(
            code: $merchantCode,
            environment: $environment,
            baseUrl: rtrim($baseUrl, '/'),
            apiKey: $apiKey,
        );
    }
}
