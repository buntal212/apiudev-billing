<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class IntegrationInvoicePreviewRequest extends ApiFormRequest
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
            'period_count' => ['nullable', 'integer', 'min:1', 'max:12'],
            'application_id' => ['prohibited'],
            'amount' => ['prohibited'],
            'currency' => ['prohibited'],
        ];
    }
}
