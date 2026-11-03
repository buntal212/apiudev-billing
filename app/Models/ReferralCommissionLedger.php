<?php

namespace App\Models;

use Database\Factories\ReferralCommissionLedgerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['partner_id', 'referral_code_id', 'customer_referral_id', 'invoice_id', 'payment_allocation_id', 'commission_type', 'amount', 'status', 'reversal_of_ledger_id', 'idempotency_key', 'notes', 'verified_at'])]
class ReferralCommissionLedger extends Model
{
    public const string TypeFirstPayment = 'pembayaran_pertama';

    public const string TypeRenewal = 'perpanjangan';

    public const string TypeReversal = 'reversal';

    public const string StatusAvailable = 'available';

    public const string StatusHeld = 'held';

    public const string StatusReversed = 'reversed';

    public const string StatusCancelled = 'cancelled';

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

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<PaymentAllocation, $this> */
    public function paymentAllocation(): BelongsTo
    {
        return $this->belongsTo(PaymentAllocation::class);
    }

    /** @return BelongsTo<self, $this> */
    public function reversalOfLedger(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_ledger_id');
    }

    /** @return HasMany<self, $this> */
    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reversal_of_ledger_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'verified_at' => 'datetime'];
    }
}
