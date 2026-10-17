<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DanaGatewayMerchantStoredConfigurationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'merchant_code' => $this['merchant_code'],
            'environment' => $this['environment'],
            'configuration_exists' => $this['configuration_exists'],
            'dana_configuration' => $this['dana_configuration'],
            'configuration' => $this['configuration'],
        ];
    }
}
