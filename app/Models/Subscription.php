<?php

namespace App\Models;

use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['application_customer_id', 'subscription_plan_id', 'starts_at', 'expires_at', 'status'])]
class Subscription extends Model
{
    public const string StatusActive = 'active';

    public const string StatusCancelled = 'cancelled';

    public const string StatusExpired = 'expired';

    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<ApplicationCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(ApplicationCustomer::class, 'application_customer_id');
    }

    /**
     * @return BelongsTo<SubscriptionPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    /**
     * @return HasMany<SubscriptionPeriodEvent, $this>
     */
    public function periodEvents(): HasMany
    {
        return $this->hasMany(SubscriptionPeriodEvent::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'starts_at' => 'datetime',
        ];
    }
}
