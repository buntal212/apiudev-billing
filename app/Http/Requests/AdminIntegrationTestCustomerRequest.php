<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class AdminIntegrationTestCustomerRequest extends ApiFormRequest
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
            'external_customer_id' => ['required', 'string', 'max:100', 'regex:/^UAT-[A-Za-z0-9._:-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'status' => ['required', 'in:active,inactive'],
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
