<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DanaPaymentMethodFee extends Model
{
    use HasFactory;

    public const string TypePercent = 'percent';
    public const string TypeFixed = 'fixed';

    protected $table = 'dana_payment_method_fees';

    protected $fillable = [
        'merchant_code',
        'payment_method',
        'payment_method_name',
        'customer_fee_type',
        'customer_fee_rate',
        'customer_fixed_amount',
        'merchant_fee_type',
        'merchant_fee_rate',
        'merchant_fixed_amount',
        'min_fee',
        'max_fee',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'customer_fee_rate' => 'decimal:4',
            'customer_fixed_amount' => 'decimal:2',
            'merchant_fee_rate' => 'decimal:4',
            'merchant_fixed_amount' => 'decimal:2',
            'min_fee' => 'decimal:2',
            'max_fee' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Calculate merchant gateway fee from a given subtotal.
     */
    public function calculateMerchantFee(float|int|string $subtotal): float
    {
        if (! $this->is_active) {
            return 0.0;
        }

        $subtotalFloat = (float) $subtotal;
        if ($subtotalFloat <= 0) {
            return 0.0;
        }

        if ($this->merchant_fee_type === self::TypePercent) {
            $rate = (float) $this->merchant_fee_rate;
            $fee = (float) round($subtotalFloat * ($rate / 100));
        } else {
            $fee = (float) round((float) $this->merchant_fixed_amount);
        }

        if ($this->min_fee !== null && (float) $this->min_fee > 0 && $fee < (float) $this->min_fee) {
            $fee = (float) round((float) $this->min_fee);
        }

        if ($this->max_fee !== null && (float) $this->max_fee > 0 && $fee > (float) $this->max_fee) {
            $fee = (float) round((float) $this->max_fee);
        }

        return $fee;
    }

    /**
     * Calculate customer fee (default 0.0).
     */
    public function calculateCustomerFee(float|int|string $subtotal): float
    {
        if (! $this->is_active) {
            return 0.0;
        }

        $subtotalFloat = (float) $subtotal;
        if ($subtotalFloat <= 0) {
            return 0.0;
        }

        if ($this->customer_fee_type === self::TypePercent) {
            $rate = (float) $this->customer_fee_rate;
            return (float) round($subtotalFloat * ($rate / 100));
        }

        return (float) round((float) $this->customer_fixed_amount);
    }
}
