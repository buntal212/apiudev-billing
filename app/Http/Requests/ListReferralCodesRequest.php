<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class ListReferralCodesRequest extends ApiFormRequest
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
        return ['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'search' => ['nullable', 'string', 'max:255'], 'partner_id' => ['nullable', 'integer', 'exists:partners,id'], 'type' => ['nullable', 'in:umum,khusus'], 'is_active' => ['nullable', 'boolean'], 'sort' => ['nullable', 'in:code,type,created_at,updated_at'], 'direction' => ['nullable', 'in:asc,desc']];
    }
}
