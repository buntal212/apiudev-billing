<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class ListDanaGatewayMerchantsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'environment' => ['required', 'in:sandbox,production'],
        ];
    }
}
