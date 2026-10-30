<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DokuMerchantCredential extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_code',
        'merchant_name',
        'client_id',
        'environment',
        'is_active',
        'encrypted_private_key',
        'key_nonce',
        'key_tag',
        'key_version',
        'public_key',
        'public_key_fingerprint',
        'last_token_tested_at',
        'last_token_test_status',
        'last_token_test_response_code',
        'last_token_test_message',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_token_tested_at' => 'datetime',
    ];

    /**
     * Mask client ID for safe display in UI/API.
     */
    public function getMaskedClientIdAttribute(): string
    {
        $clientId = (string) $this->client_id;
        $length = strlen($clientId);

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($clientId, 0, 4) . '****' . substr($clientId, -4);
    }
}
