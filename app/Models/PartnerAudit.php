<?php

namespace App\Models;

use Database\Factories\PartnerAuditFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['partner_id', 'referral_code_id', 'user_id', 'action', 'before', 'after', 'ip_address'])]
class PartnerAudit extends Model
{
    /** @use HasFactory<PartnerAuditFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }
}
