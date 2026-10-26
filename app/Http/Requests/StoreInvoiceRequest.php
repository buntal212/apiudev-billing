<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

class StoreInvoiceRequest extends ApiFormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
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
            'application_customer_id' => ['required', 'integer', 'exists:application_customers,id'],
            'subscription_plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
            'subscription_id' => ['nullable', 'integer', 'exists:subscriptions,id'],
            'period_count' => ['nullable', 'integer', 'min:1', 'max:12'],
            'due_at' => ['required', 'date'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'amount' => ['prohibited'],
            'currency' => ['prohibited'],
            'duration_days' => ['prohibited'],
            'metadata' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $idempotencyKey = $this->header('Idempotency-Key', $this->input('idempotency_key'));

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
                if ($validator->errors()->has('due_at')) {
                    return;
                }

                if ($this->date('due_at')->lessThanOrEqualTo(now())) {
                    $validator->errors()->add('due_at', 'Tanggal jatuh tempo harus setelah waktu penerbitan invoice.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'amount.prohibited' => 'Nominal tagihan ditentukan berdasarkan paket langganan.',
            'currency.prohibited' => 'Mata uang ditentukan berdasarkan paket langganan.',
            'duration_days.prohibited' => 'Durasi ditentukan berdasarkan paket langganan.',
            'metadata.prohibited' => 'Data tambahan tidak dapat dikirim saat membuat invoice.',
        ];
    }
}
