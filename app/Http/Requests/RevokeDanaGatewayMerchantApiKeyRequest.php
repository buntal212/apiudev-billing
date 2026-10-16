<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class RevokeDanaGatewayMerchantApiKeyRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'confirm_revoke' => ['required', 'accepted'],
            'service_token' => ['prohibited'],
            'idempotency_key' => ['prohibited'],
        ];
    }
}
