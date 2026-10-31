<?php

namespace App\Http\Requests;

class StoreDokuConfigurationRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user()?->is_admin ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'merchant_code' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_-]+$/'],
            'merchant_name' => ['required', 'string', 'max:100'],
            'client_id' => ['required', 'string', 'max:100'],
            'private_key' => [
                'nullable',
                'string',
                function ($attribute, $value, $fail) {
                    if (! empty(trim((string) $value))) {
                        $res = @openssl_pkey_get_private($value);
                        if ($res === false) {
                            $fail('Private key harus merupakan RSA private key PEM yang valid.');
                        }
                    }
                },
            ],
            'environment' => ['nullable', 'string', 'in:sandbox'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'merchant_code.regex' => 'Kode merchant hanya boleh menggunakan huruf kecil, angka, tanda strip, atau garis bawah.',
            'environment.in' => 'Hanya lingkungan Sandbox yang didukung pada tahap ini.',
        ]);
    }
}
