<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class StoreApplicationPaymentGatewayRequest extends ApiFormRequest
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
        $rules = [
            'application_id' => [
                'required',
                'integer',
                'exists:applications,id',
                Rule::unique('application_payment_gateways', 'application_id')
                    ->where('provider', $this->input('provider'))
                    ->where('environment', $this->input('environment')),
            ],
            'provider' => ['required', 'in:dana,doku'],
            'environment' => ['required', 'in:sandbox,production'],
            'gateway_merchant_code' => ['required', 'string', 'max:60'],
            'is_active' => ['required', 'boolean'],
        ];

        if ($this->input('provider') === 'doku') {
            $rules['environment'] = ['required', 'in:sandbox'];
        }

        return $rules;
    }
}
