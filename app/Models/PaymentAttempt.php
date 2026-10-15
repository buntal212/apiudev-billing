<?php

namespace App\Models;

use Database\Factories\PaymentAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['invoice_id', 'attempt_number', 'provider', 'merchant_reference', 'gateway_merchant_code', 'gateway_environment', 'provider_reference', 'gateway_payment_id', 'checkout_url', 'checkout_expires_at', 'amount', 'currency', 'status', 'idempotency_key', 'requested_at', 'completed_at', 'failure_code', 'failure_message', 'metadata'])]
class PaymentAttempt extends Model
{
    public const string StatusInitiated = 'initiated';

    public const string StatusPending = 'pending';

    public const string StatusSucceeded = 'succeeded';

    public const string StatusFailed = 'failed';

    public const string StatusExpired = 'expired';

    public const string StatusCancelled = 'cancelled';

    /** @use HasFactory<PaymentAttemptFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'checkout_expires_at' => 'datetime',
            'checkout_url' => 'encrypted',
            'completed_at' => 'datetime',
            'metadata' => 'array',
            'requested_at' => 'datetime',
        ];
    }
}
