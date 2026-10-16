<?php

namespace App\Models;

use Database\Factories\DanaMerchantSecretStoreAuditFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'dana_merchant_api_key_secret_id',
    'gateway_merchant_id',
    'merchant_code',
    'environment',
    'gateway_api_key_id',
    'operation',
    'status',
    'encryption_key_version',
    'safe_details',
])]
class DanaMerchantSecretStoreAudit extends Model
{
    /** @use HasFactory<DanaMerchantSecretStoreAuditFactory> */
    use HasFactory;

    /** @return BelongsTo<DanaMerchantApiKeySecret, $this> */
    public function secret(): BelongsTo
    {
        return $this->belongsTo(DanaMerchantApiKeySecret::class, 'dana_merchant_api_key_secret_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['safe_details' => 'array'];
    }
}
