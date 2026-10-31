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
        $environment = $this->input('environment', $gateway->environment);

        $envRules = [
            'sometimes',
            'in:sandbox,production',
            Rule::unique('application_payment_gateways', 'application_id')
                ->where('provider', $gateway->provider)
                ->where('environment', $environment)
                ->ignore($gateway),
        ];

        if ($gateway->provider === 'doku') {
            $envRules = [
                'sometimes',
                'in:sandbox',
                Rule::unique('application_payment_gateways', 'application_id')
                    ->where('provider', 'doku')
                    ->where('environment', 'sandbox')
                    ->ignore($gateway),
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
}
