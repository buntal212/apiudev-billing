<?php

namespace App\Models;

use Database\Factories\CustomerReferralFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['application_id', 'application_customer_id', 'partner_id', 'referral_code_id', 'referral_code', 'referral_type', 'base_price', 'discount_amount', 'customer_price', 'locked_at'])]
class CustomerReferral extends Model
{
    /** @use HasFactory<CustomerReferralFactory> */
    use HasFactory;

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** @return BelongsTo<ApplicationCustomer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(ApplicationCustomer::class, 'application_customer_id');
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return BelongsTo<ReferralCode, $this> */
    public function referralCodeRelation(): BelongsTo
    {
        return $this->belongsTo(ReferralCode::class, 'referral_code_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['base_price' => 'decimal:2', 'discount_amount' => 'decimal:2', 'customer_price' => 'decimal:2', 'locked_at' => 'datetime'];
    }
}
