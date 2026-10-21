<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

class StoreDanaSandboxTestTransactionRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount_minor' => ['required', 'integer', 'min:1', 'max:999999999999999'],
            'currency' => ['prohibited'],
            'environment' => ['prohibited'],
            'idempotency_key' => ['prohibited'],
            'metadata' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $idempotencyKey = $this->header('Idempotency-Key');
            if (! is_string($idempotencyKey) || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{15,127}\z/', $idempotencyKey) !== 1) {
                $validator->errors()->add('Idempotency-Key', 'Header Idempotency-Key wajib berupa identifier tindakan yang valid.');
            }
        }];
    }

    /** @return array{amount_minor: int} */
    public function transactionAttributes(): array
    {
        return ['amount_minor' => $this->integer('amount_minor')];
    }

    public function idempotencyKey(): string
    {
        return (string) $this->header('Idempotency-Key');
    }
}
