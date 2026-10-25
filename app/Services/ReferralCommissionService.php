<?php

namespace App\Services;

use App\Models\CustomerReferral;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\ReferralCommissionLedger;

class ReferralCommissionService
{
    /** Mencatat komisi hanya dari alokasi pembayaran yang telah terverifikasi. */
    public function recordVerifiedAllocation(PaymentAllocation $allocation): ?ReferralCommissionLedger
    {
        $invoice = $allocation->invoice()->first();
        if ($invoice === null) {
            return null;
        }

        if ($invoice->status !== Invoice::StatusPaid) {
            return null;
        }

        $referral = CustomerReferral::query()->where('application_customer_id', $invoice->application_customer_id)
            ->where('application_id', $invoice->customer()->value('application_id'))->first();
        if ($referral === null) {
            return null;
        }

        $isFirstPayment = $invoice->subscription_id === null;

        return ReferralCommissionLedger::query()->firstOrCreate(
            ['payment_allocation_id' => $allocation->getKey()],
            [
                'partner_id' => $referral->partner_id,
                'referral_code_id' => $referral->referral_code_id,
                'customer_referral_id' => $referral->getKey(),
                'invoice_id' => $invoice->getKey(),
                'commission_type' => $isFirstPayment ? ReferralCommissionLedger::TypeFirstPayment : ReferralCommissionLedger::TypeRenewal,
                'amount' => $isFirstPayment ? ReferralCommissionLedger::FirstPaymentAmount : ReferralCommissionLedger::RenewalAmount,
                'verified_at' => $allocation->allocated_at,
            ],
        );
    }
}
