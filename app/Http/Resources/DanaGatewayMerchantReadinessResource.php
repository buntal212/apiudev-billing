<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DanaGatewayMerchantReadinessResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'merchant_code' => $this['merchant_code'],
            'merchant_status' => $this['merchant_status'],
            'environment' => $this['environment'],
            'active_api_key_count' => $this['active_api_key_count'],
            'dana_configuration' => [
                'available' => $this['dana_configuration']['available'],
                'enabled' => $this['dana_configuration']['enabled'],
                'ready' => $this['dana_configuration']['ready'],
            ],
            'readiness_status' => $this['readiness_status'],
            'not_ready_components' => $this['not_ready_components'],
        ];
    }
}
