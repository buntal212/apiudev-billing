<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class ListInternalDanaGatewayMerchantsRequest extends ApiFormRequest
{
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
            'search' => ['nullable', 'string', 'max:180'],
            'status' => ['nullable', 'in:active,suspended,closed'],
            'environment' => ['nullable', 'in:sandbox,production'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
