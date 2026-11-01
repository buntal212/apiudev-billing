<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class StartInvoiceCheckoutRequest extends ApiFormRequest
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
            'idempotency_key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'payment_method' => ['nullable', 'string', 'in:dana_wallet,qris,va_bri,va_pani,va_cimb,va_btpn,gopay,bank_transfer,va_bca,va_bni,va_permata,va_mandiri,echannel'],
            'bank' => ['nullable', 'string', 'in:bca,bni,bri,permata,mandiri,echannel'],
            'provider' => ['nullable', 'string', 'in:dana,midtrans'],
            'amount' => ['prohibited'],
            'currency' => ['prohibited'],
            'merchant' => ['prohibited'],
            'merchant_code' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $idempotencyKey = $this->header('Idempotency-Key');

        if ($idempotencyKey !== null) {
            $this->merge(['idempotency_key' => $idempotencyKey]);
        }
    }
}
