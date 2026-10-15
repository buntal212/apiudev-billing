<?php

namespace App\Services;

use App\Models\ApplicationPaymentGateway;
use App\Models\CheckoutOutboxMessage;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CheckoutService
{
    public function __construct(
        private DanaGatewayClient $gatewayClient,
        private DanaGatewayMerchantResolver $merchantResolver,
    ) {}

    public function startCheckout(Invoice $invoice, string $requestIdempotencyKey): PaymentAttempt
    {
        $outbox = $this->prepareCheckout($invoice, $requestIdempotencyKey);
        $this->dispatch($outbox->getKey());

        return PaymentAttempt::query()->findOrFail($outbox->payment_attempt_id);
    }

    public function retryCheckout(PaymentAttempt $paymentAttempt): PaymentAttempt
    {
        $outbox = CheckoutOutboxMessage::query()
            ->where('payment_attempt_id', $paymentAttempt->getKey())
            ->firstOrFail();
        $this->dispatch($outbox->getKey());

        return PaymentAttempt::query()->findOrFail($paymentAttempt->getKey());
    }

    public function dispatchPending(int $limit = 50): int
    {
        $outboxIds = CheckoutOutboxMessage::query()
            ->where('status', CheckoutOutboxMessage::StatusPending)
            ->where(function ($query): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($outboxIds as $outboxId) {
            $this->dispatch($outboxId);
        }

        return $outboxIds->count();
    }

    private function prepareCheckout(Invoice $invoice, string $requestIdempotencyKey): CheckoutOutboxMessage
    {
        try {
            return DB::transaction(function () use ($invoice, $requestIdempotencyKey): CheckoutOutboxMessage {
                $lockedInvoice = Invoice::query()
                    ->with('plan')
                    ->lockForUpdate()
                    ->findOrFail($invoice->getKey());
                $this->assertInvoiceCanStartCheckout($lockedInvoice);

                $activeAttempt = PaymentAttempt::query()
                    ->where('invoice_id', $lockedInvoice->getKey())
                    ->whereIn('status', [PaymentAttempt::StatusInitiated, PaymentAttempt::StatusPending])
                    ->lockForUpdate()
                    ->latest('attempt_number')
                    ->first();

                if ($activeAttempt !== null) {
                    return CheckoutOutboxMessage::query()
                        ->where('payment_attempt_id', $activeAttempt->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                $environment = (string) config('services.dana_gateway.environment', 'sandbox');
                $mapping = ApplicationPaymentGateway::query()
                    ->where('application_id', $lockedInvoice->plan->application_id)
                    ->where('provider', 'dana')
                    ->where('environment', $environment)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if ($mapping === null) {
                    throw ValidationException::withMessages([
                        'invoice' => ['Mapping merchant DANA untuk aplikasi ini belum tersedia.'],
                    ]);
                }

                $attemptNumber = (int) PaymentAttempt::query()
                    ->where('invoice_id', $lockedInvoice->getKey())
                    ->lockForUpdate()
                    ->max('attempt_number') + 1;
                $merchantReference = $lockedInvoice->invoice_number.'-A'.$attemptNumber;

                $attempt = PaymentAttempt::query()->create([
                    'invoice_id' => $lockedInvoice->getKey(),
                    'attempt_number' => $attemptNumber,
                    'provider' => 'dana',
                    'merchant_reference' => $merchantReference,
                    'gateway_merchant_code' => $mapping->gateway_merchant_code,
                    'gateway_environment' => $mapping->environment,
                    'amount' => $lockedInvoice->amount,
                    'currency' => $lockedInvoice->currency,
                    'status' => PaymentAttempt::StatusInitiated,
                    'idempotency_key' => 'checkout:'.$lockedInvoice->getKey().':'.$requestIdempotencyKey,
                    'requested_at' => now(),
                    'metadata' => [
                        'invoice_number' => $lockedInvoice->invoice_number,
                        'application_customer_id' => $lockedInvoice->application_customer_id,
                        'subscription_id' => $lockedInvoice->subscription_id,
                    ],
                ]);

                return CheckoutOutboxMessage::query()->create([
                    'payment_attempt_id' => $attempt->getKey(),
                    'idempotency_key' => 'dana-checkout-attempt-'.$attempt->getKey(),
                    'status' => CheckoutOutboxMessage::StatusPending,
                ]);
            });
        } catch (QueryException $exception) {
            throw new RuntimeException('Checkout tidak dapat disiapkan secara aman.', previous: $exception);
        }
    }

    private function dispatch(int $outboxId): void
    {
        $claimed = DB::transaction(function () use ($outboxId): ?CheckoutOutboxMessage {
            $outbox = CheckoutOutboxMessage::query()
                ->with('paymentAttempt.invoice')
                ->lockForUpdate()
                ->findOrFail($outboxId);

            if ($outbox->status === CheckoutOutboxMessage::StatusCompleted) {
                return null;
            }

            if ($outbox->status === CheckoutOutboxMessage::StatusPending && $outbox->available_at?->isFuture()) {
                return null;
            }

            if ($outbox->status === CheckoutOutboxMessage::StatusProcessing && $outbox->locked_at?->greaterThan(now()->subMinute())) {
                return null;
            }

            $outbox->update([
                'status' => CheckoutOutboxMessage::StatusProcessing,
                'attempts' => $outbox->attempts + 1,
                'locked_at' => now(),
                'error_code' => null,
            ]);

            return $outbox->fresh(['paymentAttempt.invoice']);
        });

        if ($claimed === null) {
            return;
        }

        try {
            $attempt = $claimed->paymentAttempt;
            $merchant = $this->merchantResolver->resolve(
                $attempt->gateway_merchant_code,
                $attempt->gateway_environment,
            );
            $amountMinor = $this->amountMinor($attempt->amount);
            $gatewayPayment = $this->gatewayClient->findPaymentByMerchantOrderId($merchant, $attempt->merchant_reference);

            if ($gatewayPayment === null) {
                $gatewayPayment = $this->gatewayClient->createPayment(
                    merchant: $merchant,
                    idempotencyKey: $claimed->idempotency_key,
                    merchantOrderId: $attempt->merchant_reference,
                    amountMinor: $amountMinor,
                    metadata: [
                        'udev_invoice_id' => $attempt->invoice_id,
                        'udev_payment_attempt_id' => $attempt->getKey(),
                        'invoice_number' => $attempt->invoice->invoice_number,
                    ],
                );
            }

            $this->storeCheckout($claimed, $gatewayPayment, $amountMinor);
        } catch (ConnectionException $exception) {
            $this->markDispatchFailure($claimed, true, 'network_timeout');
        } catch (DanaGatewayException $exception) {
            $this->markDispatchFailure($claimed, $exception->retryable, $exception->retryable ? 'gateway_unavailable' : 'gateway_response_invalid');
        } catch (\Throwable) {
            $this->markDispatchFailure($claimed, false, 'checkout_processing_failed');
        }
    }

    /** @param array<string, mixed> $gatewayPayment */
    private function storeCheckout(CheckoutOutboxMessage $claimed, array $gatewayPayment, int $amountMinor): void
    {
        $gatewayPaymentId = $gatewayPayment['id'] ?? null;
        $merchantOrderId = $gatewayPayment['merchant_order_id'] ?? null;
        $gatewayAmount = $gatewayPayment['amount_minor'] ?? null;
        $currency = $gatewayPayment['currency'] ?? null;
        $status = $gatewayPayment['status'] ?? null;
        $checkoutUrl = $gatewayPayment['checkout_url'] ?? null;
        $expiresAt = $gatewayPayment['expires_at'] ?? null;

        if (! is_string($gatewayPaymentId)
            || ! is_string($merchantOrderId)
            || ! is_int($gatewayAmount)
            || ! is_string($currency)
            || $status !== PaymentAttempt::StatusPending
            || ! is_string($checkoutUrl)
            || ! filter_var($checkoutUrl, FILTER_VALIDATE_URL)
            || ! is_string($expiresAt)) {
            throw new DanaGatewayException('Respons checkout gateway tidak lengkap.', false);
        }

        $checkoutExpiresAt = CarbonImmutable::parse($expiresAt);
        if ($checkoutExpiresAt->lessThanOrEqualTo(now())) {
            throw new DanaGatewayException('Checkout gateway sudah tidak valid.', false);
        }

        DB::transaction(function () use ($claimed, $gatewayPaymentId, $merchantOrderId, $gatewayAmount, $currency, $checkoutUrl, $checkoutExpiresAt, $amountMinor): void {
            $outbox = CheckoutOutboxMessage::query()->lockForUpdate()->findOrFail($claimed->getKey());
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($outbox->payment_attempt_id);
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($attempt->invoice_id);

            $this->assertInvoiceCanStartCheckout($invoice);
            if ($attempt->merchant_reference !== $merchantOrderId
                || $gatewayAmount !== $amountMinor
                || $currency !== $attempt->currency
                || $attempt->gateway_payment_id !== null && $attempt->gateway_payment_id !== $gatewayPaymentId) {
                throw new DanaGatewayException('Referensi atau nominal gateway tidak cocok.', false);
            }

            $attempt->update([
                'gateway_payment_id' => $gatewayPaymentId,
                'checkout_url' => $checkoutUrl,
                'checkout_expires_at' => $checkoutExpiresAt,
                'status' => PaymentAttempt::StatusPending,
            ]);
            $outbox->update([
                'status' => CheckoutOutboxMessage::StatusCompleted,
                'locked_at' => null,
                'available_at' => null,
                'error_code' => null,
            ]);
        });
    }

    private function markDispatchFailure(CheckoutOutboxMessage $claimed, bool $retryable, string $errorCode): void
    {
        DB::transaction(function () use ($claimed, $retryable, $errorCode): void {
            $outbox = CheckoutOutboxMessage::query()->lockForUpdate()->findOrFail($claimed->getKey());
            if ($outbox->status === CheckoutOutboxMessage::StatusCompleted) {
                return;
            }

            $outbox->update([
                'status' => $retryable ? CheckoutOutboxMessage::StatusPending : CheckoutOutboxMessage::StatusFailed,
                'locked_at' => null,
                'available_at' => $retryable ? now()->addSeconds(5) : null,
                'error_code' => $errorCode,
            ]);
        });
    }

    private function assertInvoiceCanStartCheckout(Invoice $invoice): void
    {
        if ($invoice->status !== Invoice::StatusPending) {
            throw ValidationException::withMessages([
                'invoice' => ['Invoice dengan status saat ini tidak dapat memulai checkout.'],
            ]);
        }

        if ($invoice->due_at->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages([
                'invoice' => ['Invoice telah kedaluwarsa dan tidak dapat memulai checkout.'],
            ]);
        }

        if ($invoice->currency !== 'IDR') {
            throw ValidationException::withMessages([
                'invoice' => ['Checkout DANA hanya mendukung mata uang IDR.'],
            ]);
        }

        $this->amountMinor($invoice->getRawOriginal('amount'));
    }

    private function amountMinor(string $amount): int
    {
        if (preg_match('/^([1-9][0-9]*)(?:\.0{1,2})?$/', $amount, $matches) !== 1 || strlen($matches[1]) > 15) {
            throw ValidationException::withMessages([
                'invoice' => ['Nominal invoice DANA harus berupa rupiah utuh tanpa pecahan.'],
            ]);
        }

        return (int) $matches[1];
    }
}
