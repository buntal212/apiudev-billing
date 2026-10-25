<?php

namespace App\Models;

use Database\Factories\ReferralCommissionLedgerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['partner_id', 'referral_code_id', 'customer_referral_id', 'invoice_id', 'payment_allocation_id', 'commission_type', 'amount', 'verified_at'])]
class ReferralCommissionLedger extends Model
{
    public const string TypeFirstPayment = 'pembayaran_pertama';

    public const string TypeRenewal = 'perpanjangan';

    public const int FirstPaymentAmount = 50000;

    public const int RenewalAmount = 7500;

    /** @use HasFactory<ReferralCommissionLedgerFactory> */
    use HasFactory;

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return BelongsTo<ReferralCode, $this> */
    public function referralCode(): BelongsTo
    {
        return $this->belongsTo(ReferralCode::class);
    }

    /** @return BelongsTo<CustomerReferral, $this> */
    public function customerReferral(): BelongsTo
    {
        return $this->belongsTo(CustomerReferral::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'verified_at' => 'datetime'];
    }
}
