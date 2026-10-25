<?php

namespace Database\Factories;

use App\Models\Partner;
use App\Models\ReferralCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReferralCode>
 */
class ReferralCodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['partner_id' => Partner::factory(), 'code' => mb_strtoupper(fake()->unique()->bothify('MITRA-####')), 'type' => ReferralCode::TypeGeneral, 'base_price' => ReferralCode::BasePrice, 'discount_amount' => ReferralCode::GeneralDiscount, 'customer_price' => ReferralCode::BasePrice - ReferralCode::GeneralDiscount, 'is_active' => true];
    }
}
