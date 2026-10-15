<?php

namespace App\Http\Requests;

use App\Models\SubscriptionPlan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class UpdateSubscriptionPlanRequest extends ApiFormRequest
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
            'application_id' => ['sometimes', 'required', 'integer', 'exists:applications,id'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                'alpha_dash:ascii',
                Rule::unique('subscription_plans', 'code')
                    ->where('application_id', $this->applicationId())
                    ->ignore($this->route('subscription_plan')),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'duration_months' => ['sometimes', 'required', 'integer', 'min:1', 'max:1200'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'required', 'string', 'size:3', 'alpha:ascii'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }

    private function applicationId(): int
    {
        /** @var SubscriptionPlan $subscriptionPlan */
        $subscriptionPlan = $this->route('subscription_plan');

        return $this->integer('application_id') ?: $subscriptionPlan->application_id;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'code' => 'Kode paket',
        ];
    }
}
