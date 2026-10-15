<?php

namespace App\Models;

use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['invoice_number', 'application_customer_id', 'subscription_plan_id', 'subscription_id', 'amount', 'currency', 'duration_months', 'idempotency_key', 'status', 'issued_at', 'due_at', 'paid_at', 'cancelled_at', 'metadata'])]
class Invoice extends Model
{
    public const string StatusPending = 'pending';

    public const string StatusPaid = 'paid';

    public const string StatusExpired = 'expired';

    public const string StatusCancelled = 'cancelled';

    /** @use HasFactory<InvoiceFactory> */
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
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return HasMany<PaymentAttempt, $this>
     */
    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'cancelled_at' => 'datetime',
            'due_at' => 'datetime',
            'issued_at' => 'datetime',
            'metadata' => 'array',
            'paid_at' => 'datetime',
        ];
    }
}
