<?php

namespace App\Http\Resources;

use App\Models\ReferralCommissionLedger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReferralCommissionLedgerResource extends JsonResource
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
            'partner_id' => $this->partner_id,
            'partner' => $this->whenLoaded('partner', fn () => [
                'id' => $this->partner->id,
                'name' => $this->partner->name,
                'whatsapp_number' => $this->partner->whatsapp_number,
                'email' => $this->partner->email,
            ]),
            'referral_code_id' => $this->referral_code_id,
            'referral_code' => $this->whenLoaded('referralCode', fn () => [
                'id' => $this->referralCode->id,
                'code' => $this->referralCode->code,
                'type' => $this->referralCode->type,
            ]),
            'customer_referral_id' => $this->customer_referral_id,
            'warung' => $this->whenLoaded('customerReferral', function () {
                $customer = $this->customerReferral?->customer;
                $application = $this->customerReferral?->application;
                return [
                    'id' => $this->customerReferral->id,
                    'customer_id' => $customer?->id,
                    'name' => $customer?->name,
                    'owner_name' => $customer?->owner_name,
                    'phone_number' => $customer?->phone_number,
                    'application_id' => $application?->id,
                    'application_name' => $application?->name,
                    'referral_code' => $this->customerReferral->referral_code,
                    'locked_at' => $this->customerReferral->locked_at?->toISOString(),
                ];
            }),
            'invoice_id' => $this->invoice_id,
            'invoice' => $this->whenLoaded('invoice', fn () => [
                'id' => $this->invoice->id,
                'invoice_number' => $this->invoice->invoice_number,
                'amount' => $this->invoice->amount,
                'status' => $this->invoice->status,
                'paid_at' => $this->invoice->paid_at?->toISOString(),
            ]),
            'payment_allocation_id' => $this->payment_allocation_id,
            'commission_type' => $this->commission_type,
            'amount' => $this->amount,
            'status' => $this->status,
            'reversal_of_ledger_id' => $this->reversal_of_ledger_id,
            'idempotency_key' => $this->idempotency_key,
            'notes' => $this->notes,
            'verified_at' => $this->verified_at?->toISOString(),
            'is_reversal' => $this->commission_type === ReferralCommissionLedger::TypeReversal,
            'reversal' => $this->whenLoaded('reversals', function () {
                $reversal = $this->reversals->first();
                return $reversal ? [
                    'id' => $reversal->id,
                    'amount' => $reversal->amount,
                    'notes' => $reversal->notes,
                    'created_at' => $reversal->created_at?->toISOString(),
                ] : null;
            }),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
