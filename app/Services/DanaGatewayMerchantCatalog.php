<?php

namespace App\Services;

use App\Models\ApplicationPaymentGateway;
use App\Models\DanaEnvironmentSetting;
use App\Models\DanaMerchantApiKeySecret;
use App\Models\DanaMerchantProvisioningAudit;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DanaGatewayMerchantCatalog
{
    private DanaMerchantApiKeySecretStore $secretStore;

    public function __construct(
        ?DanaMerchantApiKeySecretStore $secretStore = null,
        private readonly ?DanaMerchantProvisioningClient $provisioningClient = null,
    ) {
        $this->secretStore = $secretStore ?? app(DanaMerchantApiKeySecretStore::class);
    }

    /**
     * @return list<array{code: string, name: string|null, is_active: bool, readiness: array{merchant_configured: bool, merchant_active: bool, credential_configured: bool, base_url_configured: bool, ready: bool}}>
     */
    public function availableFor(string $environment): array
    {
        $codes = collect();

        // 1. Dynamic database sources (only merchants with active configured secrets or existing assignments)
        if (Schema::hasTable('dana_merchant_api_key_secrets')) {
            try {
                $secretCodes = DanaMerchantApiKeySecret::query()
                    ->where('environment', $environment)
                    ->where('status', DanaMerchantApiKeySecret::StatusActive)
                    ->pluck('merchant_code');
                $codes = $codes->merge($secretCodes);
            } catch (\Throwable) {
            }
        }

        if (Schema::hasTable('application_payment_gateways')) {
            try {
                $appCodes = ApplicationPaymentGateway::query()
                    ->where('provider', 'dana')
                    ->where('environment', $environment)
                    ->pluck('gateway_merchant_code');
                $codes = $codes->merge($appCodes);
            } catch (\Throwable) {
            }
        }

        if (Schema::hasTable('dana_environment_settings')) {
            try {
                $primaryCode = DanaEnvironmentSetting::query()
                    ->where('gateway', 'dana')
                    ->value('primary_merchant_code');
                if (is_string($primaryCode) && trim($primaryCode) !== '') {
                    $codes->push(trim($primaryCode));
                }
            } catch (\Throwable) {
            }
        }

        // 2. Legacy .env catalog fallback for transition compatibility
        $legacyMerchants = $this->merchantsFor($environment);
        $codes = $codes->merge(array_keys($legacyMerchants));

        return $codes
            ->filter(fn (mixed $code): bool => is_string($code) && trim($code) !== '')
            ->unique()
            ->values()
            ->map(function (string $merchantCode) use ($environment, $legacyMerchants): array {
                $legacyConfig = $legacyMerchants[$merchantCode] ?? [];
                $readiness = $this->readiness($merchantCode, $environment);

                return [
                    'code' => $merchantCode,
                    'name' => is_string($legacyConfig['name'] ?? null) ? $legacyConfig['name'] : $merchantCode,
                    'is_active' => $readiness['merchant_active'],
                    'readiness' => $readiness,
                ];
            })
            ->all();
    }

    /**
     * @return array{merchant_configured: bool, merchant_active: bool, credential_configured: bool, base_url_configured: bool, ready: bool}
     */
    public function readiness(string $merchantCode, string $environment): array
    {
        $baseUrl = config("services.dana_gateway.base_urls.{$environment}");
        $baseUrlConfigured = is_string($baseUrl) && $baseUrl !== '';

        // 1. Check credential configured
        // Priority A: Database secret store (dana_merchant_api_key_secrets)
        $hasDbSecret = false;
        try {
            $hasDbSecret = $this->secretStore->hasActiveSecretForMerchantCode($merchantCode, $environment);
        } catch (\Throwable) {
            $hasDbSecret = false;
        }

        // Priority B: Legacy .env merchant_api_keys
        $keys = config('services.dana_gateway.merchant_api_keys', []);
        $legacyApiKey = is_array($keys) && is_array($keys[$environment] ?? null)
            ? $keys[$environment][$merchantCode] ?? null
            : null;
        $hasLegacySecret = is_string($legacyApiKey) && $legacyApiKey !== '';

        $credentialConfigured = $hasDbSecret || $hasLegacySecret;

        // 2. Check merchant configured
        // Priority A: Database registration (audits, secrets, mapping, primary setting)
        $hasDbRecord = false;
        if (Schema::hasTable('dana_merchant_provisioning_audits')) {
            try {
                $hasDbRecord = DanaMerchantProvisioningAudit::query()
                    ->where('operation', 'dana_merchant.provision')
                    ->where('merchant_code', $merchantCode)
                    ->where('status', 'succeeded')
                    ->where('merchant_code', '!=', 'larissama')
                    ->exists();
            } catch (\Throwable) {
                $hasDbRecord = false;
            }
        }

        if (! $hasDbRecord && $hasDbSecret) {
            $hasDbRecord = true;
        }

        if (! $hasDbRecord && Schema::hasTable('dana_environment_settings')) {
            try {
                $hasDbRecord = DanaEnvironmentSetting::query()
                    ->where('gateway', 'dana')
                    ->where('primary_merchant_code', $merchantCode)
                    ->exists();
            } catch (\Throwable) {
            }
        }

        if (! $hasDbRecord && Schema::hasTable('application_payment_gateways')) {
            try {
                $hasDbRecord = ApplicationPaymentGateway::query()
                    ->where('provider', 'dana')
                    ->where('gateway_merchant_code', $merchantCode)
                    ->exists();
            } catch (\Throwable) {
            }
        }

        // Priority B: Legacy config (services.dana_gateway.merchants)
        $legacyMerchant = $this->merchantConfiguration($merchantCode, $environment);
        $hasLegacyMerchant = $legacyMerchant !== null;

        $merchantConfigured = $hasDbRecord || $hasLegacyMerchant;

        // 3. Check merchant active
        $merchantActive = false;
        if ($merchantConfigured) {
            if ($hasLegacyMerchant && ! (bool) ($legacyMerchant['is_active'] ?? true)) {
                $merchantActive = false;
            } elseif ($hasLegacyMerchant && ! $hasDbRecord && ! $hasDbSecret) {
                // If merchant only exists in legacy .env, respect its is_active flag
                $merchantActive = (bool) ($legacyMerchant['is_active'] ?? false);
            } else {
                // For database merchants, check if explicitly deactivated
                $isDeactivated = false;
                if (Schema::hasTable('dana_merchant_provisioning_audits')) {
                    try {
                        $statusAudit = DanaMerchantProvisioningAudit::query()
                            ->where('merchant_code', $merchantCode)
                            ->where('environment', $environment)
                            ->whereIn('operation', ['dana_merchant.activated', 'dana_merchant.deactivated'])
                            ->where('status', 'succeeded')
                            ->latest('id')
                            ->first();

                        if ($statusAudit !== null && $statusAudit->operation === 'dana_merchant.deactivated') {
                            $isDeactivated = true;
                        }
                    } catch (\Throwable) {
                    }
                }

                $merchantActive = ! $isDeactivated;
            }
        }

        // 4. Validate readiness via gateway DANA if provisioning client is available
        if ($merchantConfigured && $merchantActive && $this->provisioningClient !== null) {
            $gatewayReadiness = $this->fetchGatewayReadiness($merchantCode, $environment);
            if ($gatewayReadiness !== null) {
                if (($gatewayReadiness['merchant_status'] ?? '') !== 'active') {
                    $merchantActive = false;
                }
            }
        }

        return [
            'merchant_configured' => $merchantConfigured,
            'merchant_active' => $merchantActive,
            'credential_configured' => $credentialConfigured,
            'base_url_configured' => $baseUrlConfigured,
            'ready' => $merchantConfigured && $merchantActive && $credentialConfigured && $baseUrlConfigured,
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

    /**
     * @return array<string, mixed>|null
     */
    private function fetchGatewayReadiness(string $merchantCode, string $environment): ?array
    {
        if ($this->provisioningClient === null || ! Schema::hasTable('dana_merchant_provisioning_audits')) {
            return null;
        }

        try {
            $audit = DanaMerchantProvisioningAudit::query()
                ->where('operation', 'dana_merchant.provision')
                ->where('merchant_code', $merchantCode)
                ->where('status', 'succeeded')
                ->latest('id')
                ->first();

            if ($audit === null || $audit->gateway_merchant_id === null) {
                return null;
            }

            return $this->provisioningClient->readiness((int) $audit->gateway_merchant_id, $environment);
        } catch (\Throwable) {
            return null;
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
