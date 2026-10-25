<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class StorePartnerRequest extends ApiFormRequest
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
        return ['name' => ['required', 'string', 'max:255'], 'whatsapp_number' => ['required', 'string', 'max:30'], 'email' => ['nullable', 'email', 'max:255'], 'address' => ['nullable', 'string'], 'is_active' => ['required', 'boolean'], 'notes' => ['nullable', 'string']];
    }
}
