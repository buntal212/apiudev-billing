<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IntegrationInvoiceResource extends JsonResource
{
    /**
     * Transform the resource into a safe integration representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $attempt = $this->relationLoaded('paymentAttempts')
            ? $this->paymentAttempts->sortByDesc('attempt_number')->first()
            : null;
        $subscription = $this->relationLoaded('subscription') ? $this->subscription : null;
        $settlement = $this->relationLoaded('settlementEvents')
            ? $this->settlementEvents->sortByDesc('id')->first()
            : null;

        return [
            'invoice_number' => $this->invoice_number,
            'status' => $this->status,
            'period_count' => $this->period_count,
            'issued_at' => $this->issued_at?->toISOString(),
            'due_at' => $this->due_at?->toISOString(),
            'paid_at' => $this->paid_at?->toISOString(),
            'plan' => [
                'code' => $metadata['subscription_plan_code'] ?? $this->plan?->code,
                'name' => $metadata['subscription_plan_name'] ?? $this->plan?->name,
                'duration_days' => $this->duration_days,
                'period_count' => $this->period_count,
            ],
            'pricing' => [
                'normal_price' => $metadata['normal_price'] ?? $this->amount,
                'referral_code' => $metadata['referral_code'] ?? null,
                'referral_type' => $metadata['referral_type'] ?? null,
                'discount_amount' => $metadata['referral_discount'] ?? '0.00',
                'final_price' => $metadata['final_price'] ?? $this->amount,
                'currency' => $this->currency,
            ],
            'payment' => $attempt ? [
                'status' => $attempt->status,
                'provider' => $attempt->provider,
                'payment_method' => $attempt->metadata['payment_method'] ?? 'dana_wallet',
                'checkout_status' => ($attempt->checkout_url !== null || ! empty($attempt->metadata['qr_content'] ?? null) || ! empty($attempt->metadata['payment_code'] ?? null))
                    ? 'ready'
                    : ($attempt->checkoutOutbox?->status === 'failed' ? 'failed' : 'processing'),
                'checkout_url' => $attempt->checkout_url,
                'qr_content' => $attempt->metadata['qr_content'] ?? null,
                'payment_code' => $attempt->metadata['payment_code'] ?? null,
                'payment_instruction' => $attempt->metadata['payment_instruction'] ?? null,
                'checkout_expires_at' => $attempt->checkout_expires_at?->toISOString(),
                'transaction_id' => $attempt->merchant_reference,
                'completed_at' => $attempt->completed_at?->toISOString(),
                'failure_code' => $attempt->failure_code,
            ] : null,
            'subscription' => $subscription ? [
                'status' => $subscription->status,
                'plan_code' => $subscription->plan?->code,
                'starts_at' => $subscription->starts_at?->toISOString(),
                'expires_at' => $subscription->expires_at?->toISOString(),
                'period_events' => $subscription->relationLoaded('periodEvents')
                    ? $subscription->periodEvents
                        ->sortByDesc('created_at')
                        ->values()
                        ->map(fn ($event): array => [
                            'event_type' => $event->event_type,
                            'previous_expires_at' => $event->previous_expires_at?->toISOString(),
                            'new_expires_at' => $event->new_expires_at?->toISOString(),
                            'transaction_id' => $event->source_id,
                            'processed_at' => $event->created_at?->toISOString(),
                        ])
                        ->all()
                    : [],
            ] : null,
            'tracking' => [
                'settlement_id' => $settlement?->id,
                'settlement_status' => $settlement?->status,
                'settlement_error_code' => $settlement?->error_code,
                'settled_at' => $settlement?->processed_at?->toISOString(),
            ],
        ];
    }
}
