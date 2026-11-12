<?php

namespace App\Http\Requests;

use App\Models\ApplicationPaymentGateway;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class UpdateApplicationPaymentGatewayRequest extends ApiFormRequest
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
        /** @var ApplicationPaymentGateway $gateway */
        $gateway = $this->route('payment_gateway');

        $envRules = [
            'sometimes',
            'in:sandbox,production',
            Rule::unique('application_payment_gateways', 'environment')
                ->where('application_id', $gateway->application_id)
                ->where('provider', $gateway->provider)
                ->ignore($gateway->id),
        ];

        if ($gateway->provider === 'doku') {
            $envRules = [
                'sometimes',
                'in:sandbox',
                Rule::unique('application_payment_gateways', 'environment')
                    ->where('application_id', $gateway->application_id)
                    ->where('provider', $gateway->provider)
                    ->ignore($gateway->id),
            ];
        } elseif ($gateway->provider === 'midtrans') {
            $envRules = [
                'sometimes',
                'in:sandbox,production',
                Rule::unique('application_payment_gateways', 'environment')
                    ->where('application_id', $gateway->application_id)
                    ->where('provider', $gateway->provider)
                    ->ignore($gateway->id),
            ];
        }

        return [
            'application_id' => ['prohibited'],
            'provider' => ['prohibited'],
            'environment' => $envRules,
            'gateway_merchant_code' => ['sometimes', 'required', 'string', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'environment.unique' => 'Aplikasi sudah memiliki mapping untuk provider dan environment ini.',
        ];
    }
}
