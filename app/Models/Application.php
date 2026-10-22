<?php

namespace App\Models;

use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'description', 'application_url', 'contact_email', 'is_active'])]
class Application extends Model
{
    /** @use HasFactory<ApplicationFactory> */
    use HasFactory;

    /**
     * @return HasMany<ApplicationCustomer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(ApplicationCustomer::class);
    }

    /**
     * @return HasMany<SubscriptionPlan, $this>
     */
    public function subscriptionPlans(): HasMany
    {
        return $this->hasMany(SubscriptionPlan::class);
    }

    /**
     * @return HasMany<ApplicationPaymentGateway, $this>
     */
    public function paymentGateways(): HasMany
    {
        return $this->hasMany(ApplicationPaymentGateway::class);
    }

    /**
     * @return HasMany<ApplicationIntegrationToken, $this>
     */
    public function integrationTokens(): HasMany
    {
        return $this->hasMany(ApplicationIntegrationToken::class);
    }

    /**
     * @return HasMany<ApplicationIntegrationAudit, $this>
     */
    public function integrationAudits(): HasMany
    {
        return $this->hasMany(ApplicationIntegrationAudit::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
