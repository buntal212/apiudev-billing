<?php

namespace App\Services;

use App\Models\ApplicationPaymentGateway;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\PaymentAttempt;
use App\Models\SettlementEvent;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class InvoiceSettlementService
{
    public function __construct(private readonly SubscriptionService $subscriptionService) {}

    public function settle(PaymentAttempt $paymentAttempt, VerifiedGatewayPayment $verification): SettlementResult
    {
        $attemptId = $paymentAttempt->getKey();
        $settlementKey = $this->settlementKey($attemptId);

        try {
            return DB::transaction(function () use ($attemptId, $settlementKey, $verification): SettlementResult {
                $attempt = PaymentAttempt::query()
                    ->lockForUpdate()
                    ->findOrFail($attemptId);
                $invoice = Invoice::query()
                    ->with(['customer', 'plan.application'])
                    ->lockForUpdate()
                    ->findOrFail($attempt->invoice_id);

                $this->assertVerificationMatchesAttempt($attempt, $invoice, $verification);

                $existingAllocation = PaymentAllocation::query()
                    ->where('payment_attempt_id', $attempt->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($existingAllocation !== null) {
                    $event = $this->completeExistingEvent($invoice, $attempt, $settlementKey);

                    return new SettlementResult(
                        invoice: $invoice->fresh(),
                        allocation: $existingAllocation->fresh(),
                        event: $event,
                        alreadySettled: true,
                    );
                }

                $invoiceAllocation = PaymentAllocation::query()
                    ->where('invoice_id', $invoice->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($invoiceAllocation !== null || $invoice->status === Invoice::StatusPaid) {
                    $this->reject('invoice_already_settled', 'Invoice sudah memiliki settlement.');
                }

                if ($invoice->status !== Invoice::StatusPending) {
                    $this->reject('invoice_not_settleable', 'Invoice dengan status saat ini tidak dapat diselesaikan.');
                }

                $this->assertVerifiedPayment($attempt, $invoice, $verification);
                $this->startEvent($invoice, $attempt, $settlementKey);

                $allocation = PaymentAllocation::query()->create([
                    'invoice_id' => $invoice->getKey(),
                    'payment_attempt_id' => $attempt->getKey(),
                    'amount' => $invoice->getRawOriginal('amount'),
                    'currency' => $invoice->currency,
                    'allocated_at' => $verification->paidAt,
                    'idempotency_key' => $settlementKey,
                ]);

                $invoice->update([
                    'status' => Invoice::StatusPaid,
                    'paid_at' => $verification->paidAt,
                ]);

                if ($invoice->subscription_id !== null) {
                    $subscription = Subscription::query()
                        ->lockForUpdate()
                        ->findOrFail($invoice->subscription_id);

                    if ($subscription->application_customer_id !== $invoice->application_customer_id
                        || $subscription->subscription_plan_id !== $invoice->subscription_plan_id) {
                        $this->reject('subscription_scope_mismatch', 'Langganan tidak sesuai dengan invoice.');
                    }

                    $this->subscriptionService->extendSubscription(
                        subscription: $subscription,
                        durationMonths: $invoice->duration_months,
                        idempotencyKey: $settlementKey.':renewal',
                        sourceType: 'payment_allocation',
                        sourceId: (string) $allocation->getKey(),
                    );
                }

                $event = SettlementEvent::query()
                    ->where('idempotency_key', $settlementKey)
                    ->lockForUpdate()
                    ->firstOrFail();
                $event->update([
                    'status' => SettlementEvent::StatusCompleted,
                    'error_code' => null,
                    'processed_at' => now(),
                ]);

                return new SettlementResult(
                    invoice: $invoice->fresh(),
                    allocation: $allocation->fresh(),
                    event: $event->fresh(),
                    alreadySettled: false,
                );
            });
        } catch (SettlementRejectedException $exception) {
            $this->recordOutcome(
                attemptId: $attemptId,
                settlementKey: $settlementKey,
                status: SettlementEvent::StatusRejected,
                errorCode: $exception->safeCode,
            );

            throw $exception;
        } catch (Throwable $exception) {
            $this->recordOutcome(
                attemptId: $attemptId,
                settlementKey: $settlementKey,
                status: SettlementEvent::StatusFailed,
                errorCode: 'settlement_processing_failed',
            );

            throw $exception;
        }
    }

    private function assertVerifiedPayment(
        PaymentAttempt $attempt,
        Invoice $invoice,
        VerifiedGatewayPayment $verification,
    ): void {
        $this->assertVerificationMatchesAttempt($attempt, $invoice, $verification);

        if ($verification->paidAt->greaterThan(CarbonImmutable::instance($invoice->due_at))) {
            $this->reject('invoice_overdue_payment', 'Pembayaran setelah jatuh tempo memerlukan keputusan pemulihan.');
        }

        if ($verification->provider === 'dana' && $verification->environment !== 'sandbox') {
            $this->reject('gateway_environment_not_allowed', 'Settlement DANA hanya diizinkan pada Sandbox.');
        }

        $customer = $invoice->customer;
        $plan = $invoice->plan;
        if ($customer === null || $plan === null || $customer->application_id !== $plan->application_id) {
            $this->reject('application_customer_mismatch', 'Aplikasi dan pelanggan invoice tidak cocok.');
        }

        $mappingExists = ApplicationPaymentGateway::query()
            ->where('application_id', $plan->application_id)
            ->where('provider', $verification->provider)
            ->where('environment', $verification->environment)
            ->where('gateway_merchant_code', $verification->merchantCode)
            ->exists();

        if (! $mappingExists) {
            $this->reject('merchant_mapping_missing', 'Mapping merchant gateway tidak ditemukan.');
        }
    }

    private function assertVerificationMatchesAttempt(
        PaymentAttempt $attempt,
        Invoice $invoice,
        VerifiedGatewayPayment $verification,
    ): void {
        if ($attempt->status !== PaymentAttempt::StatusSucceeded) {
            $this->reject('payment_not_verified', 'Pembayaran belum berstatus berhasil dari gateway terverifikasi.');
        }

        if ($verification->provider !== strtolower($attempt->provider)
            || $verification->gatewayPaymentId !== (string) $attempt->gateway_payment_id
            || $verification->merchantOrderId !== (string) $attempt->merchant_reference) {
            $this->reject('gateway_identity_mismatch', 'Identitas pembayaran gateway tidak cocok.');
        }

        if ($attempt->gateway_merchant_code === null
            || $attempt->gateway_environment === null
            || $verification->merchantCode !== $attempt->gateway_merchant_code
            || $verification->environment !== $attempt->gateway_environment) {
            $this->reject('merchant_identity_mismatch', 'Merchant gateway tidak cocok dengan payment attempt.');
        }

        if (strtoupper((string) $attempt->currency) !== $verification->currency
            || strtoupper((string) $invoice->currency) !== $verification->currency
            || $this->amountMinor($attempt->getRawOriginal('amount')) !== $verification->amountMinor
            || $this->amountMinor($invoice->getRawOriginal('amount')) !== $verification->amountMinor) {
            $this->reject('amount_or_currency_mismatch', 'Nominal atau currency pembayaran tidak cocok.');
        }
    }

    private function startEvent(Invoice $invoice, PaymentAttempt $attempt, string $settlementKey): SettlementEvent
    {
        $event = SettlementEvent::query()
            ->where('idempotency_key', $settlementKey)
            ->lockForUpdate()
            ->first();

        if ($event === null) {
            return SettlementEvent::query()->create([
                'invoice_id' => $invoice->getKey(),
                'payment_attempt_id' => $attempt->getKey(),
                'event_type' => SettlementEvent::EventSettlement,
                'status' => SettlementEvent::StatusProcessing,
                'idempotency_key' => $settlementKey,
            ]);
        }

        if ($event->status === SettlementEvent::StatusCompleted) {
            $this->reject('settlement_state_inconsistent', 'Settlement sudah selesai tetapi allocation tidak ditemukan.');
        }

        $event->update([
            'invoice_id' => $invoice->getKey(),
            'payment_attempt_id' => $attempt->getKey(),
            'event_type' => SettlementEvent::EventSettlement,
            'status' => SettlementEvent::StatusProcessing,
            'error_code' => null,
            'processed_at' => null,
        ]);

        return $event->refresh();
    }

    private function completeExistingEvent(Invoice $invoice, PaymentAttempt $attempt, string $settlementKey): SettlementEvent
    {
        $event = SettlementEvent::query()
            ->where('idempotency_key', $settlementKey)
            ->lockForUpdate()
            ->first();

        if ($event === null) {
            return SettlementEvent::query()->create([
                'invoice_id' => $invoice->getKey(),
                'payment_attempt_id' => $attempt->getKey(),
                'event_type' => SettlementEvent::EventSettlement,
                'status' => SettlementEvent::StatusCompleted,
                'idempotency_key' => $settlementKey,
                'processed_at' => now(),
            ]);
        }

        if ($event->status !== SettlementEvent::StatusCompleted) {
            $event->update([
                'status' => SettlementEvent::StatusCompleted,
                'error_code' => null,
                'processed_at' => $event->processed_at ?? now(),
            ]);
        }

        return $event->refresh();
    }

    private function recordOutcome(
        int|string $attemptId,
        string $settlementKey,
        string $status,
        string $errorCode,
    ): void {
        try {
            DB::transaction(function () use ($attemptId, $settlementKey, $status, $errorCode): void {
                $attempt = PaymentAttempt::query()->find($attemptId);
                if ($attempt === null) {
                    return;
                }

                $event = SettlementEvent::query()
                    ->where('idempotency_key', $settlementKey)
                    ->lockForUpdate()
                    ->first();

                if ($event?->status === SettlementEvent::StatusCompleted) {
                    return;
                }

                if ($event === null) {
                    SettlementEvent::query()->create([
                        'invoice_id' => $attempt->invoice_id,
                        'payment_attempt_id' => $attempt->getKey(),
                        'event_type' => SettlementEvent::EventSettlement,
                        'status' => $status,
                        'idempotency_key' => $settlementKey,
                        'error_code' => $errorCode,
                        'processed_at' => now(),
                    ]);

                    return;
                }

                $event->update([
                    'status' => $status,
                    'error_code' => $errorCode,
                    'processed_at' => now(),
                ]);
            });
        } catch (QueryException) {
            // Audit failure tidak boleh mengubah hasil utama atau mengekspose data sensitif.
        }
    }

    private function settlementKey(int|string $attemptId): string
    {
        return 'settlement:payment-attempt:'.$attemptId;
    }

    private function amountMinor(string $amount): int
    {
        if (preg_match('/^([1-9][0-9]*)(?:\.0{1,2})?$/', $amount, $matches) !== 1) {
            $this->reject('invalid_invoice_amount', 'Nominal invoice tidak valid untuk settlement gateway.');
        }

        return (int) $matches[1];
    }

    private function reject(string $code, string $message): never
    {
        throw new SettlementRejectedException($code, $message);
    }
}
