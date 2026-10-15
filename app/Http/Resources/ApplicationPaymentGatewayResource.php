<?php

namespace App\Http\Resources;

use App\Services\DanaGatewayMerchantCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationPaymentGatewayResource extends JsonResource
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
            'application' => [
                'id' => $this->application?->id,
                'code' => $this->application?->code,
                'name' => $this->application?->name,
            ],
            'provider' => $this->provider,
            'environment' => $this->environment,
            'gateway_merchant_code' => $this->gateway_merchant_code,
            'is_active' => $this->is_active,
            'readiness' => app(DanaGatewayMerchantCatalog::class)
                ->readiness($this->gateway_merchant_code, $this->environment),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
