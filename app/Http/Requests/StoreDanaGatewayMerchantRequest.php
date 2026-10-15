<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class StoreDanaGatewayMerchantRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'alpha_dash:ascii', 'max:60'],
            'name' => ['required', 'string', 'max:180'],
            'email' => ['nullable', 'email', 'max:255'],
            'environment' => ['required', 'in:sandbox,production'],
            'service_token' => ['prohibited'],
            'idempotency_key' => ['prohibited'],
        ];
    }
}
