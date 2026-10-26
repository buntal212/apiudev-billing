<?php

namespace App\Services;

use App\Models\ApplicationCustomer;
use App\Models\CustomerReferral;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function createInvoice(
        int $customerId,
        int $planId,
        ?int $subscriptionId,
        DateTimeInterface $dueAt,
        string $idempotencyKey,
        int $periodCount = 1,
    ): Invoice {
        $dueAt = CarbonImmutable::instance($dueAt)->setMicrosecond(0);

        if ($dueAt->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages([
                'due_at' => ['Tanggal jatuh tempo harus setelah waktu penerbitan invoice.'],
            ]);
        }
        if ($periodCount < 1 || $periodCount > 12) {
            throw ValidationException::withMessages(['period_count' => ['Jumlah periode harus antara 1 dan 12.']]);
        }

        try {
            return DB::transaction(function () use (
                $customerId,
                $planId,
                $subscriptionId,
                $dueAt,
                $idempotencyKey,
                $periodCount,
            ): Invoice {
                $lockedCustomer = ApplicationCustomer::query()
                    ->lockForUpdate()
                    ->findOrFail($customerId);
                $lockedPlan = SubscriptionPlan::query()
                    ->lockForUpdate()
                    ->findOrFail($planId);
                $existingInvoice = Invoice::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingInvoice) {
                    if ($existingInvoice->application_customer_id !== $lockedCustomer->getKey()) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => ['Kunci idempotensi telah digunakan untuk pelanggan lain.'],
                        ]);
                    }

                    return $existingInvoice;
                }

                if ($lockedCustomer->application_id !== $lockedPlan->application_id) {
                    throw ValidationException::withMessages([
                        'subscription_plan_id' => ['Paket langganan harus berasal dari aplikasi yang sama dengan pelanggan.'],
                    ]);
                }

                if (! $lockedPlan->is_active) {
                    throw ValidationException::withMessages([
                        'subscription_plan_id' => ['Paket langganan yang dipilih tidak aktif.'],
                    ]);
                }

                $lockedSubscription = $subscriptionId === null
                    ? Subscription::query()->where('application_customer_id', $lockedCustomer->id)->latest('expires_at')->lockForUpdate()->first()
                    : Subscription::query()->lockForUpdate()->findOrFail($subscriptionId);

                if ($lockedSubscription && $lockedSubscription->application_customer_id !== $lockedCustomer->id) {
                    throw ValidationException::withMessages([
                        'subscription_id' => ['Langganan harus sesuai dengan pelanggan yang dipilih.'],
                    ]);
                }

                $referral = CustomerReferral::query()
                    ->where('application_customer_id', $lockedCustomer->id)
                    ->lockForUpdate()
                    ->first();
                if ($lockedPlan->duration_days !== 30) {
                    throw ValidationException::withMessages(['subscription_plan_id' => ['Paket untuk invoice periode harus berdurasi dasar 30 hari.']]);
                }
                $normalPrice = $this->multiplyPrice($lockedPlan->price, $periodCount);
                $discountAmount = $this->multiplyPrice($referral?->discount_amount ?? '0.00', $periodCount);
                $finalPrice = $this->multiplyPrice($referral?->customer_price ?? $lockedPlan->price, $periodCount);

                $duplicateInvoice = Invoice::query()
                    ->where('application_customer_id', $lockedCustomer->id)
                    ->where('subscription_plan_id', $lockedPlan->id)
                    ->where('status', Invoice::StatusPending)
                    ->where('due_at', $dueAt)
                    ->when(
                        $lockedSubscription,
                        fn ($query) => $query->where('subscription_id', $lockedSubscription->id),
                        fn ($query) => $query->whereNull('subscription_id'),
                    )
                    ->lockForUpdate()
                    ->exists();

                if ($duplicateInvoice) {
                    throw ValidationException::withMessages([
                        'invoice' => ['Tagihan pending untuk kewajiban yang sama sudah tersedia.'],
                    ]);
                }

                return Invoice::create([
                    'invoice_number' => $this->makeInvoiceNumber(),
                    'application_customer_id' => $lockedCustomer->id,
                    'subscription_plan_id' => $lockedPlan->id,
                    'subscription_id' => $lockedSubscription?->id,
                    'amount' => $finalPrice,
                    'currency' => $lockedPlan->currency,
                    'duration_months' => $periodCount,
                    'duration_days' => 30 * $periodCount,
                    'period_count' => $periodCount,
                    'idempotency_key' => $idempotencyKey,
                    'status' => Invoice::StatusPending,
                    'issued_at' => now(),
                    'due_at' => $dueAt,
                    'metadata' => [
                        'subscription_plan_code' => $lockedPlan->code,
                        'subscription_plan_name' => $lockedPlan->name,
                        'period_count' => $periodCount,
                        'unit_price' => $lockedPlan->price,
                        'normal_price' => $normalPrice,
                        'referral_code' => $referral?->referral_code,
                        'referral_partner_name' => $referral?->partner()->value('name'),
                        'referral_type' => $referral?->referral_type,
                        'referral_discount' => $discountAmount,
                        'final_price' => $finalPrice,
                    ],
                ]);
            });
        } catch (QueryException $exception) {
            $existingInvoice = Invoice::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingInvoice) {
                return $existingInvoice;
            }

            throw $exception;
        }
    }

    private function makeInvoiceNumber(): string
    {
        do {
            $invoiceNumber = 'INV-'.now()->format('Ymd').'-'.Str::upper(Str::random(10));
        } while (Invoice::query()->where('invoice_number', $invoiceNumber)->exists());

        return $invoiceNumber;
    }

    private function multiplyPrice(string $price, int $multiplier): string
    {
        if (preg_match('/\A(\d+)(?:\.(\d{1,2}))?\z/', $price, $matches) !== 1) {
            throw ValidationException::withMessages(['subscription_plan_id' => ['Harga paket tidak valid.']]);
        }

        $cents = ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '0', 2, '0');
        $total = $cents * $multiplier;

        return intdiv($total, 100).'.'.str_pad((string) ($total % 100), 2, '0', STR_PAD_LEFT);
    }
}
