<?php

namespace App\Models;

use Database\Factories\DanaMerchantApiKeySecretFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'gateway_merchant_id',
    'merchant_code',
    'environment',
    'gateway_api_key_id',
    'key_prefix',
    'key_version',
    'encryption_key_version',
    'status',
    'ciphertext',
    'nonce',
    'authentication_tag',
    'key_fingerprint',
])]
class DanaMerchantApiKeySecret extends Model
{
    /** @use HasFactory<DanaMerchantApiKeySecretFactory> */
    use HasFactory;

    public const string StatusPending = 'pending';

    public const string StatusActive = 'active';

    public const string StatusRotationPending = 'rotation_pending';

    public const string StatusRevoked = 'revoked';

    public const string StatusFailed = 'failed';

    /** @var list<string> */
    protected $hidden = ['ciphertext', 'nonce', 'authentication_tag', 'key_fingerprint'];

    /** @return HasMany<DanaMerchantSecretStoreAudit, $this> */
    public function audits(): HasMany
    {
        return $this->hasMany(DanaMerchantSecretStoreAudit::class);
    }
}
