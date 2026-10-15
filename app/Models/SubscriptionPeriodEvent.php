<?php

namespace App\Models;

use Database\Factories\SubscriptionPeriodEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['subscription_id', 'event_type', 'previous_expires_at', 'new_expires_at', 'source_type', 'source_id', 'idempotency_key', 'notes', 'created_at'])]
class SubscriptionPeriodEvent extends Model
{
    /** @use HasFactory<SubscriptionPeriodEventFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'new_expires_at' => 'datetime',
            'previous_expires_at' => 'datetime',
        ];
    }
}
