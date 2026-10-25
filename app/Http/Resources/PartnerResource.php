<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PartnerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'whatsapp_number' => $this->whatsapp_number, 'email' => $this->email, 'address' => $this->address, 'notes' => $this->notes, 'is_active' => $this->is_active, 'referral_codes_count' => $this->when(isset($this->referral_codes_count), $this->referral_codes_count), 'commission_total' => $this->when(isset($this->commission_total), $this->commission_total), 'referral_codes' => ReferralCodeResource::collection($this->whenLoaded('referralCodes')), 'created_at' => $this->created_at, 'updated_at' => $this->updated_at];
    }
}
