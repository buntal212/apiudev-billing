<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationCustomerResource extends JsonResource
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
            'application_id' => $this->application_id,
            'application' => ApplicationResource::make($this->whenLoaded('application')),
            'external_customer_id' => $this->external_customer_id,
            'name' => $this->name,
            'owner_name' => $this->owner_name,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'is_active' => $this->is_active,
            'source' => $this->source,
            'first_synced_at' => $this->first_synced_at?->toISOString(),
            'last_synced_at' => $this->last_synced_at?->toISOString(),
            'sync_status' => $this->sync_status,
            'sync_error_code' => $this->sync_error_code,
            'invoices_count' => $this->when(isset($this->invoices_count), $this->invoices_count),
            'subscriptions_count' => $this->when(isset($this->subscriptions_count), $this->subscriptions_count),
            'invoices' => InvoiceResource::collection($this->whenLoaded('invoices')),
            'subscriptions' => SubscriptionResource::collection($this->whenLoaded('subscriptions')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
