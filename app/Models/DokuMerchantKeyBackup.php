<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DokuMerchantKeyBackup extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_code',
        'environment',
        'encrypted_private_key',
        'key_nonce',
        'key_tag',
        'key_version',
        'public_key',
        'public_key_fingerprint',
        'rotated_at',
        'admin_user_id',
        'reason',
    ];

    protected $casts = [
        'rotated_at' => 'datetime',
    ];

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
