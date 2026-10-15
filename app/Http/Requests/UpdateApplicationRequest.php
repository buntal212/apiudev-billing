<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class UpdateApplicationRequest extends ApiFormRequest
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
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                'alpha_dash:ascii',
                Rule::unique('applications', 'code')->ignore($this->route('application')),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'application_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
