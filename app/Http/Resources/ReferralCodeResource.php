<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReferralCodeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'partner_id' => $this->partner_id, 'partner_name' => $this->whenLoaded('partner', fn () => $this->partner?->name), 'code' => $this->code, 'type' => $this->type, 'base_price' => $this->base_price, 'discount_amount' => $this->discount_amount, 'customer_price' => $this->customer_price, 'is_active' => $this->is_active, 'usages_count' => $this->when(isset($this->customer_referrals_count), $this->customer_referrals_count), 'created_at' => $this->created_at, 'updated_at' => $this->updated_at];
    }
}
