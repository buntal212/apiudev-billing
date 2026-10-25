<?php

namespace Database\Factories;

use App\Models\CustomerReferral;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\PaymentAllocation;
use App\Models\ReferralCode;
use App\Models\ReferralCommissionLedger;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReferralCommissionLedger>
 */
class ReferralCommissionLedgerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['partner_id' => Partner::factory(), 'referral_code_id' => ReferralCode::factory(), 'customer_referral_id' => CustomerReferral::factory(), 'invoice_id' => Invoice::factory(), 'payment_allocation_id' => PaymentAllocation::factory(), 'commission_type' => ReferralCommissionLedger::TypeFirstPayment, 'amount' => ReferralCommissionLedger::FirstPaymentAmount, 'verified_at' => now()];
    }
}
