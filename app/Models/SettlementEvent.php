<?php

namespace App\Models;

use Database\Factories\SettlementEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['invoice_id', 'payment_attempt_id', 'event_type', 'status', 'idempotency_key', 'error_code', 'processed_at'])]
class SettlementEvent extends Model
{
    public const string EventSettlement = 'payment_settlement';

    public const string StatusProcessing = 'processing';

    public const string StatusCompleted = 'completed';

    public const string StatusFailed = 'failed';

    public const string StatusRejected = 'rejected';

    /** @use HasFactory<SettlementEventFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<PaymentAttempt, $this>
     */
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
            'processed_at' => 'datetime',
        ];
    }
}
