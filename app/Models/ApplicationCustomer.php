<?php

namespace App\Models;

use Database\Factories\ApplicationCustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['application_id', 'external_customer_id', 'name', 'owner_name', 'email', 'phone_number', 'is_active', 'source', 'first_synced_at', 'last_synced_at', 'sync_status', 'sync_error_code'])]
class ApplicationCustomer extends Model
{
    /** @use HasFactory<ApplicationCustomerFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
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
            'is_active' => 'boolean',
            'first_synced_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }
}
