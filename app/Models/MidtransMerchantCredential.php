<?php

namespace App\Models;

use App\Services\MidtransSecretStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MidtransMerchantCredential extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_code',
        'merchant_name',
        'midtrans_merchant_id',
        'client_key',
        'environment',
        'is_active',
        'encrypted_server_key',
        'server_key_nonce',
        'server_key_tag',
        'encrypted_signature_key',
        'signature_key_nonce',
        'signature_key_tag',
        'key_version',
        'last_tested_at',
        'last_test_status',
        'last_test_response_code',
        'last_test_message',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_tested_at' => 'datetime',
    ];

    /**
     * Mask client key for safe display in UI/API.
     */
    public function getMaskedClientKeyAttribute(): string
    {
        $key = (string) $this->client_key;
        $length = strlen($key);

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($key, 0, 8).'****'.substr($key, -4);
    }

    /**
     * Decrypt and return server key using secret store.
     */
    public function getServerKey(?MidtransSecretStore $store = null): ?string
    {
        if (empty($this->encrypted_server_key) || empty($this->server_key_nonce) || empty($this->server_key_tag)) {
            return null;
        }

        $store = $store ?? app(MidtransSecretStore::class);

        return $store->decryptSecret(
            $this->encrypted_server_key,
            $this->server_key_nonce,
            $this->server_key_tag,
            $this->merchant_code,
            'server_key',
            $this->key_version ?? 'v1',
            $this->environment ?? 'sandbox',
        );
    }

    /**
     * Decrypt and return signature secret key using secret store.
     */
    public function getSignatureKey(?MidtransSecretStore $store = null): ?string
    {
        if (empty($this->encrypted_signature_key) || empty($this->signature_key_nonce) || empty($this->signature_key_tag)) {
            return null;
        }

        $store = $store ?? app(MidtransSecretStore::class);

        return $store->decryptSecret(
            $this->encrypted_signature_key,
            $this->signature_key_nonce,
            $this->signature_key_tag,
            $this->merchant_code,
            'signature_key',
            $this->key_version ?? 'v1',
            $this->environment ?? 'sandbox',
        );
    }
}
