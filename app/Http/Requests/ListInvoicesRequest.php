<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class ListInvoicesRequest extends ApiFormRequest
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
            'subscription_id' => ['nullable', 'integer', 'exists:subscriptions,id'],
            'status' => ['nullable', 'in:pending,paid,expired,cancelled'],
            'due_from' => ['nullable', 'date'],
            'due_to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'in:invoice_number,amount,status,issued_at,due_at,paid_at,created_at'],
            'direction' => ['nullable', 'in:asc,desc'],
        ];
    }
}
