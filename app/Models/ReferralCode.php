<?php

namespace App\Models;

use Database\Factories\ReferralCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['partner_id', 'code', 'type', 'base_price', 'discount_amount', 'customer_price', 'is_active', 'created_by'])]
class ReferralCode extends Model
{
    public const string TypeGeneral = 'umum';

    public const string TypeSpecial = 'khusus';

    public const int BasePrice = 175000;

    public const int GeneralDiscount = 50000;

    public const int SpecialDiscount = 75000;

    /** @use HasFactory<ReferralCodeFactory> */
    use HasFactory;

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return HasMany<CustomerReferral, $this> */
    public function customerReferrals(): HasMany
    {
        return $this->hasMany(CustomerReferral::class);
    }

    /** @return HasMany<ReferralCommissionLedger, $this> */
    public function commissionLedgers(): HasMany
    {
        return $this->hasMany(ReferralCommissionLedger::class);
    }

    /** @return array{0: int, 1: int, 2: int} */
    public static function pricingFor(string $type): array
    {
        $discount = $type === self::TypeSpecial ? self::SpecialDiscount : self::GeneralDiscount;

        return [self::BasePrice, $discount, self::BasePrice - $discount];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'customer_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
