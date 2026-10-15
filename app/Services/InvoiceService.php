<?php

namespace App\Services;

use App\Models\ApplicationCustomer;
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
    ): Invoice {
        $dueAt = CarbonImmutable::instance($dueAt)->setMicrosecond(0);

        if ($dueAt->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages([
                'due_at' => ['Tanggal jatuh tempo harus setelah waktu penerbitan invoice.'],
            ]);
        }

        try {
            return DB::transaction(function () use (
                $customerId,
                $planId,
                $subscriptionId,
                $dueAt,
                $idempotencyKey,
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
                    ? null
                    : Subscription::query()->lockForUpdate()->findOrFail($subscriptionId);

                if ($lockedSubscription && (
                    $lockedSubscription->application_customer_id !== $lockedCustomer->id
                    || $lockedSubscription->subscription_plan_id !== $lockedPlan->id
                )) {
                    throw ValidationException::withMessages([
                        'subscription_id' => ['Langganan harus sesuai dengan pelanggan dan paket yang dipilih.'],
                    ]);
                }

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
                    'amount' => $lockedPlan->price,
                    'currency' => $lockedPlan->currency,
                    'duration_months' => $lockedPlan->duration_months,
                    'idempotency_key' => $idempotencyKey,
                    'status' => Invoice::StatusPending,
                    'issued_at' => now(),
                    'due_at' => $dueAt,
                    'metadata' => [
                        'subscription_plan_code' => $lockedPlan->code,
                        'subscription_plan_name' => $lockedPlan->name,
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
}
