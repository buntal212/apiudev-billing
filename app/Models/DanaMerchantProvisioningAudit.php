<?php

namespace App\Models;

use Database\Factories\DanaMerchantProvisioningAuditFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'admin_user_id',
    'operation',
    'merchant_code',
    'gateway_merchant_id',
    'environment',
    'idempotency_fingerprint',
    'status',
    'gateway_status',
    'safe_details',
])]
class DanaMerchantProvisioningAudit extends Model
{
    /** @use HasFactory<DanaMerchantProvisioningAuditFactory> */
    use HasFactory;

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'safe_details' => 'array',
        ];
    }
}
