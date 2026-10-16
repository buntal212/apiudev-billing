<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DanaMerchantApiKeyIssuance extends Model
{
    protected $fillable = ['admin_user_id', 'gateway_merchant_id', 'merchant_code', 'environment', 'idempotency_key', 'request_fingerprint', 'gateway_issuance_id', 'gateway_api_key_id', 'key_prefix', 'status', 'secret_stored_at', 'confirmed_at', 'cancelled_at'];

    protected function casts(): array
    {
        return ['secret_stored_at' => 'datetime', 'confirmed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }
}
