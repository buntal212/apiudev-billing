<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class AdminIntegrationTestCustomerUpdateRequest extends ApiFormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'owner_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'status' => ['sometimes', 'required', 'in:active,inactive'],
            'external_customer_id' => ['prohibited'],
            'application_id' => ['prohibited'],
            'subscription_id' => ['prohibited'],
            'invoice' => ['prohibited'],
            'amount' => ['prohibited'],
            'paid_at' => ['prohibited'],
            'expires_at' => ['prohibited'],
            'password' => ['prohibited'],
            'is_test' => ['prohibited'],
        ];
    }
}
