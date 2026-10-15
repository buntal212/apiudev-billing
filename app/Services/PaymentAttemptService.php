<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PaymentAttempt;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentAttemptService
{
    public function recordAttempt(
        Invoice $invoice,
        string $provider,
        string $idempotencyKey,
        ?string $merchantReference = null,
    ): PaymentAttempt {
        try {
            return DB::transaction(function () use (
                $invoice,
                $provider,
                $idempotencyKey,
                $merchantReference,
            ): PaymentAttempt {
                $lockedInvoice = Invoice::query()
                    ->lockForUpdate()
                    ->findOrFail($invoice->getKey());
                $existingAttempt = PaymentAttempt::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingAttempt) {
                    return $existingAttempt;
                }

                $this->assertInvoiceCanAcceptAttempt($lockedInvoice);

                $hasActiveAttempt = PaymentAttempt::query()
                    ->where('invoice_id', $lockedInvoice->id)
                    ->whereIn('status', [PaymentAttempt::StatusInitiated, PaymentAttempt::StatusPending])
                    ->lockForUpdate()
                    ->exists();

                if ($hasActiveAttempt) {
                    throw ValidationException::withMessages([
                        'invoice' => ['Invoice sudah memiliki percobaan pembayaran aktif.'],
                    ]);
                }

                $attemptNumber = (int) PaymentAttempt::query()
                    ->where('invoice_id', $lockedInvoice->id)
                    ->lockForUpdate()
                    ->max('attempt_number') + 1;

                return PaymentAttempt::create([
                    'invoice_id' => $lockedInvoice->id,
                    'attempt_number' => $attemptNumber,
                    'provider' => $provider,
                    'merchant_reference' => $merchantReference,
                    'amount' => $lockedInvoice->amount,
                    'currency' => $lockedInvoice->currency,
                    'status' => PaymentAttempt::StatusInitiated,
                    'idempotency_key' => $idempotencyKey,
                    'requested_at' => now(),
                ]);
            });
        } catch (QueryException $exception) {
            $existingAttempt = PaymentAttempt::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingAttempt) {
                return $existingAttempt;
            }

            throw $exception;
        }
    }

    public function transitionStatus(
        PaymentAttempt $paymentAttempt,
        string $status,
        ?string $providerReference = null,
        ?string $failureCode = null,
        ?string $failureMessage = null,
        ?DateTimeInterface $completedAt = null,
    ): PaymentAttempt {
        return DB::transaction(function () use (
            $paymentAttempt,
            $status,
            $providerReference,
            $failureCode,
            $failureMessage,
            $completedAt,
        ): PaymentAttempt {
            $lockedAttempt = PaymentAttempt::query()
                ->lockForUpdate()
                ->findOrFail($paymentAttempt->getKey());
            $lockedInvoice = Invoice::query()
                ->lockForUpdate()
                ->findOrFail($lockedAttempt->invoice_id);

            $this->assertInvoiceCanAcceptAttempt($lockedInvoice);

            if ($lockedAttempt->status === $status) {
                return $lockedAttempt;
            }

            $validTransitions = [
                PaymentAttempt::StatusInitiated => [
                    PaymentAttempt::StatusPending,
                    PaymentAttempt::StatusFailed,
                    PaymentAttempt::StatusExpired,
                    PaymentAttempt::StatusCancelled,
                ],
                PaymentAttempt::StatusPending => [
                    PaymentAttempt::StatusSucceeded,
                    PaymentAttempt::StatusFailed,
                    PaymentAttempt::StatusExpired,
                    PaymentAttempt::StatusCancelled,
                ],
            ];

            if (! in_array($status, $validTransitions[$lockedAttempt->status] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Perubahan status percobaan pembayaran tidak valid.'],
                ]);
            }

            $isCompleted = in_array($status, [
                PaymentAttempt::StatusSucceeded,
                PaymentAttempt::StatusFailed,
                PaymentAttempt::StatusExpired,
                PaymentAttempt::StatusCancelled,
            ], true);

            $lockedAttempt->update([
                'status' => $status,
                'provider_reference' => $providerReference ?? $lockedAttempt->provider_reference,
                'failure_code' => $failureCode,
                'failure_message' => $failureMessage,
                'completed_at' => $isCompleted
                    ? CarbonImmutable::instance($completedAt ?? now())
                    : null,
            ]);

            return $lockedAttempt->refresh();
        });
    }

    private function assertInvoiceCanAcceptAttempt(Invoice $invoice): void
    {
        if (in_array($invoice->status, [
            Invoice::StatusPaid,
            Invoice::StatusCancelled,
            Invoice::StatusExpired,
        ], true)) {
            throw ValidationException::withMessages([
                'invoice' => ['Invoice dengan status saat ini tidak dapat menerima percobaan pembayaran.'],
            ]);
        }
    }
}
