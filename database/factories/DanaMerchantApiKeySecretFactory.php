<?php

namespace Database\Factories;

use App\Models\DanaMerchantApiKeySecret;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DanaMerchantApiKeySecret> */
class DanaMerchantApiKeySecretFactory extends Factory
{
    public function definition(): array
    {
        return [
            'gateway_merchant_id' => fake()->numberBetween(1, 100_000),
            'merchant_code' => fake()->unique()->slug(),
            'environment' => 'sandbox',
            'gateway_api_key_id' => fake()->numberBetween(1, 100_000),
            'key_prefix' => 'dpk_'.fake()->lexify('??????'),
            'key_version' => 1,
            'encryption_key_version' => 'v1',
            'status' => DanaMerchantApiKeySecret::StatusActive,
            'ciphertext' => base64_encode(random_bytes(32)),
            'nonce' => random_bytes(12),
            'authentication_tag' => random_bytes(16),
            'key_fingerprint' => hash('sha256', fake()->uuid()),
        ];
    }
}
