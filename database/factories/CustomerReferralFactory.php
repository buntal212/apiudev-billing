<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\CustomerReferral;
use App\Models\Partner;
use App\Models\ReferralCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerReferral>
 */
class CustomerReferralFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['application_id' => Application::factory(), 'application_customer_id' => ApplicationCustomer::factory(), 'partner_id' => Partner::factory(), 'referral_code_id' => ReferralCode::factory(), 'referral_code' => mb_strtoupper(fake()->bothify('MITRA-####')), 'referral_type' => ReferralCode::TypeGeneral, 'base_price' => ReferralCode::BasePrice, 'discount_amount' => ReferralCode::GeneralDiscount, 'customer_price' => ReferralCode::BasePrice - ReferralCode::GeneralDiscount, 'locked_at' => now()];
    }
}
