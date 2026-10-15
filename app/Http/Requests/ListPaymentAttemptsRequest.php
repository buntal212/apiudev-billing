<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class ListPaymentAttemptsRequest extends ApiFormRequest
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
            'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
            'provider' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'in:initiated,pending,succeeded,failed,expired,cancelled'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'in:attempt_number,provider,status,requested_at,completed_at,created_at'],
            'direction' => ['nullable', 'in:asc,desc'],
        ];
    }
}
