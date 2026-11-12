<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DanaPaymentFeeSetting extends Model
{
    use HasFactory;

    public const string FeeTypeDana = 'dana';
    public const string FeeTypeUdev = 'udev';

    public const string FeeUnitRupiah = 'rupiah';
    public const string FeeUnitPercent = 'percent';

    public const string EnvironmentSandbox = 'sandbox';
    public const string EnvironmentProduction = 'production';

    public const array SupportedPaymentMethods = [
        'qris',
        'dana_wallet',
        'va_bri',
        'va_cimb',
        'va_btpn',
        'va_pani',
    ];

    protected $table = 'dana_payment_fee_settings';

    protected $fillable = [
        'gateway_merchant_code',
        'application_id',
        'scope_key',
        'payment_method',
        'environment',
        'fee_type',
        'fee_value',
        'fee_unit',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'application_id' => 'integer',
            'fee_value' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (DanaPaymentFeeSetting $setting): void {
            $setting->scope_key = self::buildScopeKey($setting->application_id, $setting->gateway_merchant_code);
        });
    }

    /**
     * Build deterministic scope key ensuring no duplicate null values in MySQL.
     */
    public static function buildScopeKey(?int $applicationId = null, ?string $merchantCode = null): string
    {
        $merchant = trim((string) $merchantCode);
        if ($applicationId !== null && $merchant !== '') {
            return "app:{$applicationId}:merchant:{$merchant}";
        }
        if ($applicationId !== null) {
            return "app:{$applicationId}";
        }
        if ($merchant !== '') {
            return "merchant:{$merchant}";
        }

        return 'global';
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'application_id');
    }

    /**
     * Compute fee amount from a given subtotal.
     */
    public function computeAmount(float|int|string $baseAmount): float
    {
        if (! $this->is_active) {
            return 0.0;
        }

        $base = (float) $baseAmount;
        if ($base <= 0) {
            return 0.0;
        }

        if ($this->fee_unit === self::FeeUnitPercent) {
            return (float) round($base * (((float) $this->fee_value) / 100));
        }

        return (float) round((float) $this->fee_value);
    }
}
