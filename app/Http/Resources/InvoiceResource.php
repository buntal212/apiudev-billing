<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $referral = $this->relationLoaded('customer') && $this->customer?->relationLoaded('referral')
            ? $this->customer->referral
            : null;

        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'application_customer_id' => $this->application_customer_id,
            'subscription_plan_id' => $this->subscription_plan_id,
            'subscription_id' => $this->subscription_id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'pricing' => [
                'normal_price' => $metadata['normal_price'] ?? $this->amount,
                'discount_amount' => $metadata['referral_discount'] ?? '0.00',
                'final_price' => $metadata['final_price'] ?? $this->amount,
                'referral_code' => $metadata['referral_code'] ?? null,
                'referral_partner_name' => $metadata['referral_partner_name']
                    ?? $referral?->partner?->name,
            ],
            'duration_days' => $this->duration_days,
            'period_count' => $this->period_count,
            'status' => $this->status,
            'issued_at' => $this->issued_at?->toISOString(),
            'due_at' => $this->due_at?->toISOString(),
            'paid_at' => $this->paid_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'metadata' => $this->metadata,
            'customer' => $this->whenLoaded('customer', fn (): array => [
                'id' => $this->customer->id,
                'application_id' => $this->customer->application_id,
                'name' => $this->customer->name,
            ]),
            'plan' => $this->whenLoaded('plan', fn (): array => [
                'id' => $this->plan->id,
                'application_id' => $this->plan->application_id,
                'code' => $this->plan->code,
                'name' => $this->plan->name,
            ]),
            'subscription' => $this->whenLoaded('subscription', fn (): ?array => $this->subscription ? [
                'id' => $this->subscription->id,
                'status' => $this->subscription->status,
                'starts_at' => $this->subscription->starts_at?->toISOString(),
                'expires_at' => $this->subscription->expires_at?->toISOString(),
                'period_events' => $this->subscription->relationLoaded('periodEvents')
                    ? $this->subscription->periodEvents
                        ->sortByDesc('created_at')
                        ->values()
                        ->map(fn ($event): array => [
                            'id' => $event->id,
                            'event_type' => $event->event_type,
                            'previous_expires_at' => $event->previous_expires_at?->toISOString(),
                            'new_expires_at' => $event->new_expires_at?->toISOString(),
                            'source_type' => $event->source_type,
                            'source_id' => $event->source_id,
                            'notes' => $event->notes,
                            'created_at' => $event->created_at?->toISOString(),
                        ])
                        ->all()
                    : [],
            ] : null),
            'payment_attempts' => PaymentAttemptResource::collection(
                $this->whenLoaded('paymentAttempts')
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
