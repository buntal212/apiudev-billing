<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class StoreReferralCodeRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => mb_strtoupper(trim($this->input('code')))]);
        }
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
        return ['partner_id' => ['required', 'integer', 'exists:partners,id'], 'code' => ['required', 'string', 'max:40', 'alpha_dash:ascii', Rule::unique('referral_codes', 'code')], 'type' => ['required', 'in:umum,khusus'], 'is_active' => ['required', 'boolean']];
    }
}
