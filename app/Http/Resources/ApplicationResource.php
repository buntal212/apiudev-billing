<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class ApplicationResource extends JsonResource
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
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'application_url' => $this->application_url,
            'contact_email' => $this->contact_email,
            'is_active' => $this->is_active,
            'customers_count' => $this->when(isset($this->customers_count), $this->customers_count),
            'integration_customers_count' => $this->when(
                isset($this->integration_customers_count),
                $this->integration_customers_count,
            ),
            'integration_tokens_count' => $this->when(isset($this->integration_tokens_count), $this->integration_tokens_count),
            'latest_customer_sync_at' => $this->when(
                isset($this->customers_max_last_synced_at),
                $this->customers_max_last_synced_at
                    ? Carbon::parse($this->customers_max_last_synced_at)->toISOString()
                    : null,
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
