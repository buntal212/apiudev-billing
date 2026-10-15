<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DanaGatewayMerchantResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'merchant_id' => $this['merchant_id'],
            'merchant_code' => $this['merchant_code'],
            'merchant_name' => $this['merchant_name'],
            'environment' => $this['environment'],
            'merchant_status' => $this['merchant_status'],
            'provisioning_status' => $this['provisioning_status'],
            'credential_status' => $this['credential_status'],
            'dana_configuration_status' => $this['dana_configuration_status'],
            'provisioned_at' => $this['provisioned_at'],
        ];
    }
}
