<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class ListApplicationCustomersRequest extends ApiFormRequest
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
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'application_id' => ['nullable', 'integer', 'exists:applications,id'],
            'search' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'source' => ['nullable', 'in:manual,integration'],
            'sort' => ['nullable', 'in:external_customer_id,name,email,created_at,updated_at'],
            'direction' => ['nullable', 'in:asc,desc'],
        ];
    }
}
