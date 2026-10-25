<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class IntegrationAttachReferralRequest extends ApiFormRequest
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
        return ['referral_code' => ['required', 'string', 'max:40', 'regex:/^REF-[A-Z0-9]{6}$/'], 'application_id' => ['prohibited'], 'customer_price' => ['prohibited'], 'discount_amount' => ['prohibited']];
    }
}
