<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerReferralResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'application_id' => $this->application_id, 'application_name' => $this->whenLoaded('application', fn () => $this->application?->name), 'application_customer_id' => $this->application_customer_id, 'customer_name' => $this->whenLoaded('customer', fn () => $this->customer?->name), 'referral_code' => $this->referral_code, 'referral_type' => $this->referral_type, 'base_price' => $this->base_price, 'discount_amount' => $this->discount_amount, 'customer_price' => $this->customer_price, 'locked_at' => $this->locked_at];
    }
}
