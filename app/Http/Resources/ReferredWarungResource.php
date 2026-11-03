<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReferredWarungResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_customer_id' => $this->application_customer_id,
            'warung_name' => $this->customer?->name,
            'owner_name' => $this->customer?->owner_name,
            'phone_number' => $this->customer?->phone_number,
            'email' => $this->customer?->email,
            'application_id' => $this->application_id,
            'application_name' => $this->application?->name,
            'partner_id' => $this->partner_id,
            'partner_name' => $this->partner?->name,
            'referral_code_id' => $this->referral_code_id,
            'referral_code' => $this->referral_code,
            'referral_type' => $this->referral_type,
            'base_price' => $this->base_price,
            'discount_amount' => $this->discount_amount,
            'customer_price' => $this->customer_price,
            'locked_at' => $this->locked_at?->toISOString(),
            'payments_count' => $this->payments_count ?? 0,
            'commissions_total' => $this->commissions_total ?? '0.00',
            'last_payment_at' => $this->last_payment_at ? \Illuminate\Support\Carbon::parse($this->last_payment_at)->toISOString() : null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
