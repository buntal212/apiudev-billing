<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MidtransEnvironmentSetting extends Model
{
    use HasFactory;

    public const string EnvironmentSandbox = 'sandbox';

    public const string EnvironmentProduction = 'production';

    protected $fillable = [
        'gateway',
        'active_environment',
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
        return static::query()->where('gateway', 'midtrans')->value('active_environment')
            ?? self::EnvironmentSandbox;
    }
}
