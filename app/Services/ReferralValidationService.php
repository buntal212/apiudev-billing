<?php

namespace App\Services;

use App\Models\ApplicationCustomer;
use App\Models\CustomerReferral;
use App\Models\ReferralCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReferralValidationService
{
    /**
     * Mengunci harga referral pada pelanggan. Pemanggilan ulang selalu memakai
     * penguncian pertama, termasuk saat kode asal sudah tidak aktif.
     */
    public function lockForCustomer(ApplicationCustomer $customer, string $submittedCode): CustomerReferral
    {
        return DB::transaction(function () use ($customer, $submittedCode): CustomerReferral {
            $lockedCustomer = ApplicationCustomer::query()->lockForUpdate()->findOrFail($customer->getKey());
            $existing = CustomerReferral::query()->where('application_customer_id', $lockedCustomer->getKey())->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }

            $code = ReferralCode::query()->with('partner')->where('code', mb_strtoupper(trim($submittedCode)))->lockForUpdate()->first();
            if ($code === null || ! $code->is_active || ! $code->partner?->is_active) {
                throw ValidationException::withMessages(['referral_code' => ['Kode referral tidak aktif atau tidak ditemukan.']]);
            }

            return CustomerReferral::query()->create([
                'application_id' => $lockedCustomer->application_id,
                'application_customer_id' => $lockedCustomer->getKey(),
                'partner_id' => $code->partner_id,
                'referral_code_id' => $code->getKey(),
                'referral_code' => $code->code,
                'referral_type' => $code->type,
                'base_price' => $code->base_price,
                'discount_amount' => $code->discount_amount,
                'customer_price' => $code->customer_price,
                'locked_at' => now(),
            ]);
        });
    }
}
