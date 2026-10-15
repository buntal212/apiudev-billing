<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionPeriodEventResource extends JsonResource
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
            'subscription_id' => $this->subscription_id,
            'event_type' => $this->event_type,
            'previous_expires_at' => $this->previous_expires_at?->toISOString(),
            'new_expires_at' => $this->new_expires_at?->toISOString(),
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
