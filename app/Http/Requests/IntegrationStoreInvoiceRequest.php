<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

class IntegrationStoreInvoiceRequest extends ApiFormRequest
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
            'subscription_plan_code' => ['required', 'string', 'max:100'],
            'due_at' => ['required', 'date'],
            'idempotency_key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'application_id' => ['prohibited'],
            'amount' => ['prohibited'],
            'currency' => ['prohibited'],
            'subscription_id' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $idempotencyKey = $this->header('Idempotency-Key');

        if ($idempotencyKey !== null) {
            $this->merge(['idempotency_key' => $idempotencyKey]);
        }
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->has('due_at') && $this->date('due_at')->lessThanOrEqualTo(now())) {
                    $validator->errors()->add('due_at', 'Tanggal jatuh tempo harus setelah waktu penerbitan invoice.');
                }
            },
        ];
    }
}
