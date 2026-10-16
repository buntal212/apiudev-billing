<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DanaGatewayMerchantApiKeyRevocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this['id'],
            'key_prefix' => $this['key_prefix'],
            'revoked_at' => $this['revoked_at'],
        ];
    }
}
