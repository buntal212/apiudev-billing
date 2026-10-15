<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class LoginRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => mb_strtolower(trim((string) $this->input('username'))),
        ]);
    }

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
            'username' => ['required', 'string', 'max:50', 'alpha_dash'],
            'password' => ['required', 'string'],
        ];
    }
}
