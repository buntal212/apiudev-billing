<?php

namespace App\Models;

use Database\Factories\ApplicationIntegrationTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['application_id', 'name', 'token_hash', 'token_prefix', 'scopes', 'expires_at', 'revoked_at', 'last_used_at'])]
class ApplicationIntegrationToken extends Model
{
    public const string ScopeCustomersRead = 'customers:read';

    public const string ScopeCustomersWrite = 'customers:write';

    /** @use HasFactory<ApplicationIntegrationTokenFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * @return HasMany<ApplicationIntegrationAudit, $this>
     */
    public function audits(): HasMany
    {
        return $this->hasMany(ApplicationIntegrationAudit::class);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }
}
