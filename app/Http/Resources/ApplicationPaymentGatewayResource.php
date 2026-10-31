<?php

namespace App\Http\Resources;

use App\Models\DokuMerchantCredential;
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
        $readiness = null;
        if ($this->provider === 'doku') {
            $cred = DokuMerchantCredential::query()
                ->where('merchant_code', $this->gateway_merchant_code)
                ->where('environment', $this->environment)
                ->first();
            $isConfigured = $cred !== null && ! empty($cred->client_id) && ! empty($cred->encrypted_private_key);
            $isActive = (bool) ($cred?->is_active ?? true);
            $readiness = [
                'merchant_configured' => $cred !== null,
                'merchant_active' => $isActive,
                'credential_configured' => $isConfigured,
                'base_url_configured' => true,
                'ready' => $isActive && $isConfigured,
            ];
        } else {
            $readiness = app(DanaGatewayMerchantCatalog::class)
                ->readiness($this->gateway_merchant_code, $this->environment);
        }

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
            'readiness' => $readiness,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
