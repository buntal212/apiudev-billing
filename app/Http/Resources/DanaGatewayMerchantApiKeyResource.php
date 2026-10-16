<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DanaGatewayMerchantApiKeyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this['id'],
            'name' => $this['name'],
            'key_prefix' => $this['key_prefix'],
            'abilities' => $this['abilities'],
            'is_active' => $this['is_active'],
            'expires_at' => $this['expires_at'],
            'revoked_at' => $this['revoked_at'],
            'last_used_at' => $this['last_used_at'],
            'created_at' => $this['created_at'],
        ];
    }
}
