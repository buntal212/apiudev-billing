<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class UpdatePartnerRequest extends ApiFormRequest
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
        return ['name' => ['sometimes', 'required', 'string', 'max:255'], 'whatsapp_number' => ['sometimes', 'required', 'string', 'max:30'], 'email' => ['sometimes', 'nullable', 'email', 'max:255'], 'address' => ['sometimes', 'nullable', 'string'], 'is_active' => ['sometimes', 'required', 'boolean'], 'notes' => ['sometimes', 'nullable', 'string']];
    }
}
