<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class ListSubscriptionPlansRequest extends ApiFormRequest
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
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:code,name,price,duration_months,created_at,updated_at'],
            'direction' => ['nullable', 'in:asc,desc'],
        ];
    }
}
