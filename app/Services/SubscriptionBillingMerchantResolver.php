<?php

namespace App\Services;

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

    public function __construct(private readonly DanaGatewayMerchantCatalog $merchantCatalog) {}

    public function resolveNewCheckoutMerchant(string $environment): string
    {
        if ($environment !== 'sandbox') {
            throw ValidationException::withMessages([
                'invoice' => ['Checkout langganan DANA hanya tersedia pada Sandbox.'],
            ]);
        }

        $merchantCode = config('services.udev_billing.subscription_dana_sandbox_merchant_code');
        if (! is_string($merchantCode) || trim($merchantCode) === '') {
            throw ValidationException::withMessages([
                'invoice' => ['Merchant penerima UDEV Sandbox belum dikonfigurasi. Checkout baru dihentikan.'],
            ]);
        }

        try {
            $this->merchantCatalog->ensureUsable($merchantCode, $environment);
        } catch (ValidationException) {
            throw ValidationException::withMessages([
                'invoice' => ['Merchant penerima UDEV Sandbox belum siap digunakan. Checkout baru dihentikan.'],
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
