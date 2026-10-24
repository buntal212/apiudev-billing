<?php

namespace App\Http\Requests;

use App\Models\ApplicationIntegrationToken;
use Illuminate\Contracts\Validation\ValidationRule;

class StoreApplicationIntegrationTokenRequest extends ApiFormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', 'in:'.implode(',', [
                ApplicationIntegrationToken::ScopeCustomersRead,
                ApplicationIntegrationToken::ScopeCustomersWrite,
                ApplicationIntegrationToken::ScopeSubscriptionPlansRead,
            ])],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
