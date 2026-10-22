<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationIntegrationAuditResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_id' => $this->application_id,
            'token_prefix' => $this->token?->token_prefix,
            'external_customer_id' => $this->external_customer_id,
            'operation' => $this->operation,
            'status' => $this->status,
            'error_code' => $this->error_code,
            'request_id' => $this->request_id,
            'safe_details' => $this->safe_details,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
