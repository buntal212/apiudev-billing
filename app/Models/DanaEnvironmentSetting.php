<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class DanaEnvironmentSetting extends Model
{
    use HasFactory;

    public const string EnvironmentSandbox = 'sandbox';

    public const string EnvironmentProduction = 'production';

    protected $fillable = [
        'gateway',
        'active_environment',
        'primary_merchant_code',
        'is_production_ready',
        'production_activated_at',
        'production_activated_by',
        'last_switched_at',
        'last_switched_by',
        'activation_notes',
    ];

    protected function casts(): array
    {
        return [
            'is_production_ready' => 'boolean',
            'production_activated_at' => 'datetime',
            'last_switched_at' => 'datetime',
        ];
    }

    public function productionActivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'production_activated_by');
    }

    public function lastSwitchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_switched_by');
    }

    public function isProduction(): bool
    {
        return $this->active_environment === self::EnvironmentProduction;
    }

    public function isSandbox(): bool
    {
        return $this->active_environment === self::EnvironmentSandbox;
    }

    public static function getActiveEnvironment(): string
    {
        if (! Schema::hasTable('dana_environment_settings')) {
            return (string) config('services.dana_gateway.environment', self::EnvironmentSandbox);
        }

        try {
            return (string) (static::query()->where('gateway', 'dana')->value('active_environment')
                ?: config('services.dana_gateway.environment', self::EnvironmentSandbox));
        } catch (\Throwable) {
            return (string) config('services.dana_gateway.environment', self::EnvironmentSandbox);
        }
    }

    public static function getPrimaryMerchantCode(string $environment = self::EnvironmentSandbox): string
    {
        if (Schema::hasTable('dana_environment_settings') && Schema::hasColumn('dana_environment_settings', 'primary_merchant_code')) {
            try {
                $code = (string) static::query()->where('gateway', 'dana')->value('primary_merchant_code');
                if (trim($code) !== '') {
                    return trim($code);
                }
            } catch (\Throwable) {
            }
        }

        $configKey = $environment === self::EnvironmentProduction
            ? 'services.udev_billing.subscription_dana_production_merchant_code'
            : 'services.udev_billing.subscription_dana_sandbox_merchant_code';

        $merchantCode = config($configKey) ?: config('services.udev_billing.subscription_dana_sandbox_merchant_code');
        if (is_string($merchantCode) && trim($merchantCode) !== '') {
            return trim($merchantCode);
        }

        return '';
    }
}
