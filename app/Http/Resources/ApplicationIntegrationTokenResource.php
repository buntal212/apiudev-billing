<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationIntegrationTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_id' => $this->application_id,
            'name' => $this->name,
            'token_kind' => $this->token_kind,
            'token_prefix' => $this->token_prefix,
            'scopes' => $this->scopes,
            'expires_at' => $this->expires_at?->toISOString(),
            'revoked_at' => $this->revoked_at?->toISOString(),
            'last_used_at' => $this->last_used_at?->toISOString(),
            'status' => $this->revoked_at !== null
                ? 'revoked'
                : ($this->expires_at !== null && $this->expires_at->isPast() ? 'expired' : 'active'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
