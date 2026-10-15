<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class ListSubscriptionsRequest extends ApiFormRequest
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
            'application_id' => ['nullable', 'integer', 'exists:applications,id'],
            'application_customer_id' => ['nullable', 'integer', 'exists:application_customers,id'],
            'subscription_plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'status' => ['nullable', 'in:active,expired,cancelled'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'in:starts_at,expires_at,status,created_at,updated_at'],
            'direction' => ['nullable', 'in:asc,desc'],
        ];
    }
}
