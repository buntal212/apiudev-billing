<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class DanaGatewayMerchantCatalog
{
    /**
     * @return list<array{code: string, name: string|null, is_active: bool, readiness: array{merchant_configured: bool, merchant_active: bool, credential_configured: bool, base_url_configured: bool, ready: bool}}>
     */
    public function availableFor(string $environment): array
    {
        $merchants = $this->merchantsFor($environment);

        return collect($merchants)
            ->map(function (mixed $configuration, mixed $code) use ($environment): array {
                $merchantCode = (string) $code;
                $merchant = is_array($configuration) ? $configuration : [];

                return [
                    'code' => $merchantCode,
                    'name' => is_string($merchant['name'] ?? null) ? $merchant['name'] : null,
                    'is_active' => (bool) ($merchant['is_active'] ?? false),
                    'readiness' => $this->readiness($merchantCode, $environment),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{merchant_configured: bool, merchant_active: bool, credential_configured: bool, base_url_configured: bool, ready: bool}
     */
    public function readiness(string $merchantCode, string $environment): array
    {
        $merchant = $this->merchantConfiguration($merchantCode, $environment);
        $merchantConfigured = $merchant !== null;
        $merchantActive = $merchantConfigured && (bool) ($merchant['is_active'] ?? false);
        $baseUrl = config("services.dana_gateway.base_urls.{$environment}");
        $keys = config('services.dana_gateway.merchant_api_keys', []);
        $apiKey = is_array($keys) && is_array($keys[$environment] ?? null)
            ? $keys[$environment][$merchantCode] ?? null
            : null;
        $credentialConfigured = is_string($apiKey) && $apiKey !== '';
        $baseUrlConfigured = is_string($baseUrl) && $baseUrl !== '';

        return [
            'merchant_configured' => $merchantConfigured,
            'merchant_active' => $merchantActive,
            'credential_configured' => $credentialConfigured,
            'base_url_configured' => $baseUrlConfigured,
            'ready' => $merchantActive && $credentialConfigured && $baseUrlConfigured,
        ];
    }

    public function ensureUsable(string $merchantCode, string $environment): void
    {
        $readiness = $this->readiness($merchantCode, $environment);

        if (! $readiness['merchant_configured']) {
            throw ValidationException::withMessages([
                'gateway_merchant_code' => 'Merchant DANA tidak terdaftar pada konfigurasi server untuk environment ini.',
            ]);
        }

        if (! $readiness['merchant_active']) {
            throw ValidationException::withMessages([
                'gateway_merchant_code' => 'Merchant DANA sedang nonaktif pada konfigurasi server.',
            ]);
        }

        if (! $readiness['ready']) {
            throw ValidationException::withMessages([
                'gateway_merchant_code' => 'Konfigurasi merchant DANA belum siap digunakan.',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function merchantsFor(string $environment): array
    {
        $catalog = config('services.dana_gateway.merchants', []);

        return is_array($catalog) && is_array($catalog[$environment] ?? null)
            ? $catalog[$environment]
            : [];
    }

    /** @return array<string, mixed>|null */
    private function merchantConfiguration(string $merchantCode, string $environment): ?array
    {
        $merchants = $this->merchantsFor($environment);

        return array_key_exists($merchantCode, $merchants) && is_array($merchants[$merchantCode])
            ? $merchants[$merchantCode]
            : null;
    }
}
