<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class ListBillingGatewayTransactionsRequest extends ApiFormRequest
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
            'customer' => ['nullable', 'string', 'max:120'],
            'invoice_number' => ['nullable', 'string', 'max:100'],
            'provider' => ['nullable', 'in:dana'],
            'status' => ['nullable', 'in:initiated,pending,succeeded,paid,failed,expired,cancelled'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'in:attempt_number,status,requested_at,completed_at,created_at'],
            'direction' => ['nullable', 'in:asc,desc'],
        ];
    }
}
