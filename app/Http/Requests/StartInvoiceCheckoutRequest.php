<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class StartInvoiceCheckoutRequest extends ApiFormRequest
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
            'idempotency_key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'amount' => ['prohibited'],
            'currency' => ['prohibited'],
            'merchant' => ['prohibited'],
            'merchant_code' => ['prohibited'],
            'provider' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $idempotencyKey = $this->header('Idempotency-Key');

        if ($idempotencyKey !== null) {
            $this->merge(['idempotency_key' => $idempotencyKey]);
        }
    }
}
