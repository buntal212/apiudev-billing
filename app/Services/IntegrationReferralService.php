<?php

namespace App\Services;

use App\Exceptions\IntegrationConflictException;
use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\CustomerReferral;
use App\Models\ReferralCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IntegrationReferralService
{
    /** @return array<string, mixed> */
    public function validate(Application $application, string $submittedCode): array
    {
        $code = $this->activeCode($application, $submittedCode);

        return $this->safeReferral($code);
    }

    public function attach(Application $application, ApplicationCustomer $customer, string $submittedCode): CustomerReferral
    {
        if ($customer->application_id !== $application->getKey()) {
            throw new IntegrationConflictException('application_customer_mismatch', 'Pelanggan tidak terdaftar pada aplikasi token ini.');
        }

        return DB::transaction(function () use ($application, $customer, $submittedCode): CustomerReferral {
            $lockedCustomer = ApplicationCustomer::query()->lockForUpdate()->findOrFail($customer->getKey());
            $existing = CustomerReferral::query()->where('application_customer_id', $lockedCustomer->getKey())->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->referral_code === mb_strtoupper(trim($submittedCode))) {
                    return $existing;
                }

                throw new IntegrationConflictException('referral_already_locked', 'Referral pelanggan sudah terkunci dan tidak dapat diganti.');
            }

            $code = $this->activeCode($application, $submittedCode, true);

            return CustomerReferral::query()->create([
                'application_id' => $application->getKey(),
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

    /** @return array<string, mixed> */
    public function safeReferral(ReferralCode $code): array
    {
        return ['code' => $code->code, 'type' => $code->type, 'base_price' => $code->base_price, 'discount_amount' => $code->discount_amount, 'customer_price' => $code->customer_price];
    }

    private function activeCode(Application $application, string $submittedCode, bool $lock = false): ReferralCode
    {
        if (! $application->is_active) {
            throw ValidationException::withMessages(['application' => ['Aplikasi tidak aktif untuk integrasi.']]);
        }

        $query = ReferralCode::query()->with('partner')->where('code', mb_strtoupper(trim($submittedCode)));
        if ($lock) {
            $query->lockForUpdate();
        }
        $code = $query->first();
        if ($code === null || ! $code->is_active || ! $code->partner?->is_active) {
            throw ValidationException::withMessages(['referral_code' => ['Kode referral tidak aktif atau tidak ditemukan.']]);
        }

        return $code;
    }
}
