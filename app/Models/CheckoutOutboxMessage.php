<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['payment_attempt_id', 'idempotency_key', 'status', 'attempts', 'available_at', 'locked_at', 'error_code'])]
class CheckoutOutboxMessage extends Model
{
    public const string StatusPending = 'pending';

    public const string StatusProcessing = 'processing';

    public const string StatusCompleted = 'completed';

    public const string StatusFailed = 'failed';

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'available_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }
}
