<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'application_id',
    'application_integration_token_id',
    'external_customer_id',
    'operation',
    'status',
    'error_code',
    'request_id',
    'safe_details',
])]
class ApplicationIntegrationAudit extends Model
{
    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * @return BelongsTo<ApplicationIntegrationToken, $this>
     */
    public function token(): BelongsTo
    {
        return $this->belongsTo(ApplicationIntegrationToken::class, 'application_integration_token_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'safe_details' => 'array',
        ];
    }
}
