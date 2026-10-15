<?php

namespace Database\Factories;

use App\Models\DanaMerchantProvisioningAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DanaMerchantProvisioningAudit>
 */
class DanaMerchantProvisioningAuditFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'admin_user_id' => User::factory(),
            'operation' => 'dana_merchant.provision',
            'merchant_code' => fake()->unique()->slug(),
            'gateway_merchant_id' => fake()->numberBetween(1, 100_000),
            'environment' => 'sandbox',
            'idempotency_fingerprint' => hash('sha256', fake()->uuid()),
            'status' => 'succeeded',
            'gateway_status' => 201,
            'safe_details' => ['provisioning_status' => 'provisioned'],
        ];
    }
}
