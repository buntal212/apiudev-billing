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
            'gateway_payment_id' => $this->gateway_payment_id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'requested_at' => $this->requested_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'failure_code' => $this->failure_code,
            'failure_message' => $this->failure_message,
            'metadata' => $this->metadata,
            'payment_method' => $this->metadata['payment_method'] ?? 'dana_wallet',
            'qr_content' => $this->metadata['qr_content'] ?? null,
            'payment_code' => $this->metadata['payment_code'] ?? null,
            'payment_instruction' => $this->metadata['payment_instruction'] ?? null,
            'checkout_url' => $this->checkout_url,
            'checkout_expires_at' => $this->checkout_expires_at?->toISOString(),
            'checkout' => $this->whenLoaded('checkoutOutbox', fn (): ?array => $this->checkoutOutbox ? [
                'status' => $this->checkoutOutbox->status,
                'attempts' => $this->checkoutOutbox->attempts,
                'error_code' => $this->checkoutOutbox->error_code,
                'available_at' => $this->checkoutOutbox->available_at?->toISOString(),
                'locked_at' => $this->checkoutOutbox->locked_at?->toISOString(),
            ] : null),
            'reconciliation' => $this->whenLoaded('latestReconciliationRun', fn (): ?array => $this->latestReconciliationRun ? [
                'status' => $this->latestReconciliationRun->status,
                'gateway_status' => $this->latestReconciliationRun->gateway_status,
                'attempts' => $this->latestReconciliationRun->attempt_number,
                'last_synced_at' => $this->latestReconciliationRun->finished_at?->toISOString(),
                'retry_after_at' => $this->latestReconciliationRun->retry_after_at?->toISOString(),
                'error_code' => $this->latestReconciliationRun->error_code,
            ] : null),
            'invoice' => $this->whenLoaded('invoice', fn (): array => [
                'id' => $this->invoice->id,
                'invoice_number' => $this->invoice->invoice_number,
                'status' => $this->invoice->status,
                'subscription' => $this->invoice->relationLoaded('subscription') && $this->invoice->subscription !== null
                    ? [
                        'id' => $this->invoice->subscription->id,
                        'status' => $this->invoice->subscription->status,
                        'starts_at' => $this->invoice->subscription->starts_at?->toISOString(),
                        'expires_at' => $this->invoice->subscription->expires_at?->toISOString(),
                    ]
                    : null,
            ]),
            'settlement' => $this->whenLoaded('paymentAllocation', fn (): ?array => $this->paymentAllocation ? [
                'id' => $this->paymentAllocation->id,
                'amount' => $this->paymentAllocation->amount,
                'currency' => $this->paymentAllocation->currency,
                'allocated_at' => $this->paymentAllocation->allocated_at?->toISOString(),
            ] : null),
            'settlement_history' => $this->whenLoaded('settlementEvents', fn (): array => $this->settlementEvents
                ->map(fn ($event): array => [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'status' => $event->status,
                    'error_code' => $event->error_code,
                    'processed_at' => $event->processed_at?->toISOString(),
                ])
                ->values()
                ->all()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
