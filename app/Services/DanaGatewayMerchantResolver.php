<?php

namespace App\Services;

use RuntimeException;

class DanaGatewayMerchantResolver
{
    public function __construct(private readonly DanaGatewayMerchantCatalog $merchantCatalog) {}

    public function resolve(string $merchantCode, string $environment): DanaGatewayMerchant
    {
        $this->merchantCatalog->ensureUsable($merchantCode, $environment);

        $baseUrl = config("services.dana_gateway.base_urls.{$environment}");
        $keys = config('services.dana_gateway.merchant_api_keys', []);
        $apiKey = is_array($keys) && is_array($keys[$environment] ?? null)
            ? $keys[$environment][$merchantCode] ?? null
            : null;

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
