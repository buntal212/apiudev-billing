<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'payment_attempt_id',
    'attempt_number',
    'status',
    'gateway_status',
    'http_status',
    'error_code',
    'retry_after_at',
    'verified_at',
    'started_at',
    'finished_at',
    'idempotency_key',
])]
class PaymentReconciliationRun extends Model
{
    public const string StatusProcessing = 'processing';

    public const string StatusPending = 'pending';

    public const string StatusPaid = 'paid';

    public const string StatusTerminal = 'terminal';

    public const string StatusRetryable = 'retryable';

    public const string StatusFailed = 'failed';

    public const string StatusSkipped = 'skipped';

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
            'retry_after_at' => 'datetime',
            'verified_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
