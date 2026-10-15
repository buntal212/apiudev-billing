<?php

namespace App\Http\Requests;

use App\Models\ApplicationCustomer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class UpdateApplicationCustomerRequest extends ApiFormRequest
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
        /** @var ApplicationCustomer $applicationCustomer */
        $applicationCustomer = $this->route('application_customer');
        $applicationId = $this->integer('application_id') ?: $applicationCustomer->application_id;

        return [
            'application_id' => ['sometimes', 'required', 'integer', 'exists:applications,id'],
            'external_customer_id' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('application_customers', 'external_customer_id')
                    ->where('application_id', $applicationId)
                    ->ignore($applicationCustomer),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:30'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
