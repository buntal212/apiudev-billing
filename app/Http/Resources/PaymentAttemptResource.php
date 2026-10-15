<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentAttemptResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'attempt_number' => $this->attempt_number,
            'provider' => $this->provider,
            'merchant_reference' => $this->merchant_reference,
            'provider_reference' => $this->provider_reference,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'requested_at' => $this->requested_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'failure_code' => $this->failure_code,
            'failure_message' => $this->failure_message,
            'metadata' => $this->metadata,
            'invoice' => $this->whenLoaded('invoice', fn (): array => [
                'id' => $this->invoice->id,
                'invoice_number' => $this->invoice->invoice_number,
                'status' => $this->invoice->status,
            ]),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
