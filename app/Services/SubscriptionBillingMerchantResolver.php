<?php

namespace App\Services;

use App\Models\DanaEnvironmentSetting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Resolves the only permitted recipient for newly created UDEV subscriptions.
 *
 * Application mappings remain a legacy verification mechanism. They must never
 * become a fallback recipient for a new subscription checkout.
 */
class SubscriptionBillingMerchantResolver
{
    public const RecipientMetadataKey = 'billing_recipient';

    public const CentralRecipient = 'udev_central';

    public function __construct(
        private readonly DanaGatewayMerchantCatalog $merchantCatalog,
        private readonly ?DanaEnvironmentService $danaEnvironmentService = null,
    ) {}

    public function resolveNewCheckoutMerchant(string $environment): string
    {
        if (! in_array($environment, ['sandbox', 'production'], true)) {
            throw ValidationException::withMessages([
                'invoice' => ['Environment DANA tidak didukung.'],
            ]);
        }

        $configKey = $environment === 'production'
            ? 'services.udev_billing.subscription_dana_production_merchant_code'
            : 'services.udev_billing.subscription_dana_sandbox_merchant_code';

        $configuredMerchant = config($configKey) ?: config('services.udev_billing.subscription_dana_sandbox_merchant_code');

        $primaryMerchantCode = $this->danaEnvironmentService?->getPrimaryMerchantCode($environment)
            ?: DanaEnvironmentSetting::getPrimaryMerchantCode($environment);

        if (empty($configuredMerchant) && ($primaryMerchantCode === '' || $primaryMerchantCode === 'udev-central')) {
            $hasDbCustomSetting = false;
            if (Schema::hasTable('dana_environment_settings') && Schema::hasColumn('dana_environment_settings', 'primary_merchant_code')) {
                try {
                    $hasDbCustomSetting = (bool) DanaEnvironmentSetting::query()
                        ->where('gateway', 'dana')
                        ->whereNotNull('primary_merchant_code')
                        ->where('primary_merchant_code', '!=', '')
                        ->where('primary_merchant_code', '!=', 'udev-central')
                        ->exists();
                } catch (\Throwable) {
                }
            }
            $merchantCode = $hasDbCustomSetting ? $primaryMerchantCode : null;
        } else {
            $merchantCode = $primaryMerchantCode ?: $configuredMerchant;
        }

        if (! is_string($merchantCode) || trim($merchantCode) === '') {
            $envLabel = ucfirst($environment);
            throw ValidationException::withMessages([
                'invoice' => ["Merchant penerima UDEV {$envLabel} belum dikonfigurasi. Checkout baru dihentikan."],
            ]);
        }

        try {
            $this->merchantCatalog->ensureUsable($merchantCode, $environment);
        } catch (ValidationException) {
            $envLabel = ucfirst($environment);
            throw ValidationException::withMessages([
                'invoice' => ["Merchant penerima UDEV {$envLabel} belum siap digunakan. Checkout baru dihentikan."],
            ]);
        }

        return $merchantCode;
    }

    /** @param array<string, mixed>|null $metadata */
    public function isCentralAttempt(?array $metadata): bool
    {
        return ($metadata[self::RecipientMetadataKey] ?? null) === self::CentralRecipient;
    }
}
