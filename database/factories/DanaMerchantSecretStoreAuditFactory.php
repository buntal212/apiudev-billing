<?php

namespace Database\Factories;

use App\Models\DanaMerchantApiKeySecret;
use App\Models\DanaMerchantSecretStoreAudit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DanaMerchantSecretStoreAudit> */
class DanaMerchantSecretStoreAuditFactory extends Factory
{
    public function definition(): array
    {
        return [
            'dana_merchant_api_key_secret_id' => DanaMerchantApiKeySecret::factory(),
            'gateway_merchant_id' => fake()->numberBetween(1, 100_000),
            'merchant_code' => fake()->slug(),
            'environment' => 'sandbox',
            'gateway_api_key_id' => fake()->numberBetween(1, 100_000),
            'operation' => 'secret.stored',
            'status' => 'succeeded',
            'encryption_key_version' => 'v1',
            'safe_details' => ['storage_verified' => true],
        ];
    }
}
