<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

class StoreDanaGatewayMerchantDanaConfigurationRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|Closure|string>
     */
    public function rules(): array
    {
        return [
            'environment' => ['required', 'string', 'in:sandbox'],
            'partner_id' => ['required', 'string', 'max:150'],
            'merchant_id' => ['required', 'string', 'max:150'],
            'private_key' => ['nullable', 'string', 'max:65535'],
            'dana_webhook_public_key' => ['nullable', 'string', 'max:65535'],
            'origin' => ['required', 'url', 'max:255', 'regex:/\Ahttps:\/\//i'],
            'channel_id' => ['required', 'string', 'max:100'],
            'notification_url' => ['required', 'url', 'max:2048', 'regex:/\Ahttps:\/\//i'],
            'callback_url' => ['required', 'url', 'max:2048', 'regex:/\Ahttps:\/\//i'],
            'mcc' => ['required', 'string', 'regex:/\A\d{4}\z/'],
            'enabled' => ['required', 'boolean'],
            'service_token' => ['prohibited'],
            'idempotency_key' => ['prohibited'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = array_keys($this->rules());
            $unexpected = array_diff(array_keys($this->all()), $allowed);

            foreach ($unexpected as $field) {
                $validator->errors()->add($field, 'Field tidak diizinkan.');
            }
        }];
    }
}
