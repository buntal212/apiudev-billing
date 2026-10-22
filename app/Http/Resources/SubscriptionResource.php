<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
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
            'application_customer_id' => $this->application_customer_id,
            'subscription_plan_id' => $this->subscription_plan_id,
            'starts_at' => $this->starts_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'status' => $this->status,
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
            'latest_invoice' => $this->whenLoaded('latestInvoice', fn (): ?array => $this->latestInvoice ? [
                'id' => $this->latestInvoice->id,
                'invoice_number' => $this->latestInvoice->invoice_number,
                'status' => $this->latestInvoice->status,
                'amount' => $this->latestInvoice->amount,
                'currency' => $this->latestInvoice->currency,
                'paid_at' => $this->latestInvoice->paid_at?->toISOString(),
            ] : null),
            'activation' => $this->whenLoaded('latestPeriodEvent', fn (): array => [
                'status' => $this->latestPeriodEvent ? 'completed' : 'not_recorded',
                'error_code' => null,
                'last_synced_at' => $this->latestPeriodEvent?->created_at?->toISOString(),
                'event_type' => $this->latestPeriodEvent?->event_type,
            ]),
            'period_events' => SubscriptionPeriodEventResource::collection(
                $this->whenLoaded('periodEvents')
            ),
            'invoices' => InvoiceResource::collection(
                $this->whenLoaded('invoices')
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
