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
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'application_customer_id' => $this->application_customer_id,
            'subscription_plan_id' => $this->subscription_plan_id,
            'subscription_id' => $this->subscription_id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'duration_days' => $this->duration_days,
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
            ] : null),
            'payment_attempts' => PaymentAttemptResource::collection(
                $this->whenLoaded('paymentAttempts')
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
