<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MidtransPaymentMethodFee extends Model
{
    use HasFactory;

    public const string TypePercent = 'percent';
    public const string TypeFixed = 'fixed';

    protected $table = 'midtrans_payment_method_fees';

    protected $fillable = [
        'merchant_code',
        'payment_method',
        'payment_method_name',
        'fee_type',
        'fee_rate',
        'fixed_amount',
        'min_fee',
        'max_fee',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'fee_rate' => 'decimal:4',
            'fixed_amount' => 'decimal:2',
            'min_fee' => 'decimal:2',
            'max_fee' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Calculate admin fee from a given subtotal after discount.
     */
    public function calculateFee(float|int|string $subtotal): float
    {
        if (! $this->is_active) {
            return 0.0;
        }

        $subtotalFloat = (float) $subtotal;
        if ($subtotalFloat <= 0) {
            return 0.0;
        }

        if ($this->fee_type === self::TypePercent) {
            $rate = (float) $this->fee_rate;
            $fee = (float) round($subtotalFloat * ($rate / 100));
        } else {
            $fee = (float) round((float) $this->fixed_amount);
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
     * Return formatted fee label for display (e.g., "0.7%" or "Rp 4.000").
     */
    public function formattedFeeLabel(): string
    {
        if ($this->fee_type === self::TypePercent) {
            $rate = (float) $this->fee_rate;
            return rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.').'%';
        }

        return 'Rp '.number_format((float) $this->fixed_amount, 0, ',', '.');
    }
}
