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

    public function startCheckout(Invoice $invoice, string $requestIdempotencyKey, string $paymentMethod = 'dana_wallet'): PaymentAttempt
    {
        $outbox = $this->prepareCheckout($invoice, $requestIdempotencyKey, $paymentMethod);
        $this->dispatch($outbox->getKey());

        return PaymentAttempt::query()->findOrFail($outbox->payment_attempt_id);
    }

    public function retryCheckout(PaymentAttempt $paymentAttempt): PaymentAttempt
    {
        $outboxId = DB::transaction(function () use ($paymentAttempt): int {
            $attempt = PaymentAttempt::query()
                ->lockForUpdate()
                ->findOrFail($paymentAttempt->getKey());
            $invoice = Invoice::query()
                ->with('plan')
                ->lockForUpdate()
                ->findOrFail($attempt->invoice_id);
            $outbox = CheckoutOutboxMessage::query()
                ->where('payment_attempt_id', $attempt->getKey())
                ->lockForUpdate()
                ->first();

            $this->assertRetryAllowed($attempt, $invoice, $outbox);

            return (int) $outbox->getKey();
        });
        $this->dispatch($outboxId);

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

    private function prepareCheckout(Invoice $invoice, string $requestIdempotencyKey, string $paymentMethod = 'dana_wallet'): CheckoutOutboxMessage
    {
        try {
            return DB::transaction(function () use ($invoice, $requestIdempotencyKey, $paymentMethod): CheckoutOutboxMessage {
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
                    if ($activeAttempt->checkout_expires_at !== null && $activeAttempt->checkout_expires_at->lessThanOrEqualTo(now())) {
                        $activeAttempt->update([
                            'status' => PaymentAttempt::StatusExpired,
                            'completed_at' => now(),
                        ]);
                        $activeAttempt = null;
                    } elseif (($activeAttempt->metadata['payment_method'] ?? 'dana_wallet') !== $paymentMethod) {
                        $activeAttempt->update([
                            'status' => PaymentAttempt::StatusCancelled,
                            'completed_at' => now(),
                        ]);
                        $activeAttempt = null;
                    }
                }

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
                        'payment_method' => $paymentMethod,
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

            // Selalu baca order yang sudah ada lebih dulu. Payment pending tanpa
            // instruksi checkout adalah hasil parsial, bukan checkout siap bayar.
            // POST dengan reference dan Idempotency-Key outbox yang sama akan
            // melanjutkan payment gateway yang sama; tidak membuat payment baru.
            if ($gatewayPayment === null || ! $this->hasCompleteGatewayCheckout($gatewayPayment)) {
                $paymentMethod = $attempt->metadata['payment_method'] ?? 'dana_wallet';
                $gatewayPayment = $this->gatewayClient->createPayment(
                    merchant: $merchant,
                    idempotencyKey: $claimed->idempotency_key,
                    merchantOrderId: $attempt->merchant_reference,
                    amountMinor: $amountMinor,
                    metadata: [
                        'udev_invoice_id' => $attempt->invoice_id,
                        'udev_payment_attempt_id' => $attempt->getKey(),
                        'invoice_number' => $attempt->invoice->invoice_number,
                        'udev_sandbox_payment_method' => $paymentMethod,
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

        $gatewayMetadata = is_array($gatewayPayment['metadata'] ?? null) ? $gatewayPayment['metadata'] : [];
        $instruction = is_array($gatewayMetadata['udev_sandbox_payment_instruction'] ?? null)
            ? $gatewayMetadata['udev_sandbox_payment_instruction']
            : null;
        $paymentCode = is_string($instruction['payment_code'] ?? null) && $instruction['payment_code'] !== ''
            ? $instruction['payment_code']
            : (is_string($gatewayMetadata['qr_content'] ?? null) ? $gatewayMetadata['qr_content'] : null);

        $hasValidCheckoutUrl = is_string($checkoutUrl) && filter_var($checkoutUrl, FILTER_VALIDATE_URL);
        $hasValidPaymentCode = is_string($paymentCode) && $paymentCode !== '';

        if (! is_string($gatewayPaymentId)
            || ! is_string($merchantOrderId)
            || ! is_int($gatewayAmount)
            || ! is_string($currency)
            || $status !== PaymentAttempt::StatusPending
            || (! $hasValidCheckoutUrl && ! $hasValidPaymentCode)
            || ! is_string($expiresAt)) {
            throw new DanaGatewayException('Respons checkout gateway tidak lengkap.', false);
        }

        $checkoutExpiresAt = CarbonImmutable::parse($expiresAt);
        if ($checkoutExpiresAt->lessThanOrEqualTo(now())) {
            throw new DanaGatewayException('Checkout gateway sudah tidak valid.', false);
        }

        DB::transaction(function () use ($claimed, $gatewayPaymentId, $merchantOrderId, $gatewayAmount, $currency, $checkoutUrl, $hasValidCheckoutUrl, $paymentCode, $instruction, $checkoutExpiresAt, $amountMinor): void {
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

            $currentMetadata = is_array($attempt->metadata) ? $attempt->metadata : [];
            if ($paymentCode !== null) {
                $currentMetadata['qr_content'] = $paymentCode;
                $currentMetadata['payment_code'] = $paymentCode;
            }
            if ($instruction !== null) {
                $currentMetadata['payment_instruction'] = $instruction;
            }

            $attempt->update([
                'gateway_payment_id' => $gatewayPaymentId,
                'checkout_url' => $hasValidCheckoutUrl ? $checkoutUrl : null,
                'checkout_expires_at' => $checkoutExpiresAt,
                'status' => PaymentAttempt::StatusPending,
                'metadata' => $currentMetadata,
            ]);
            $outbox->update([
                'status' => CheckoutOutboxMessage::StatusCompleted,
                'locked_at' => null,
                'available_at' => null,
                'error_code' => null,
            ]);
        });
    }

    /** @param array<string, mixed> $gatewayPayment */
    private function hasCompleteGatewayCheckout(array $gatewayPayment): bool
    {
        $checkoutUrl = $gatewayPayment['checkout_url'] ?? null;
        $metadata = is_array($gatewayPayment['metadata'] ?? null) ? $gatewayPayment['metadata'] : [];
        $instruction = is_array($metadata['udev_sandbox_payment_instruction'] ?? null)
            ? $metadata['udev_sandbox_payment_instruction']
            : [];
        $paymentCode = $instruction['payment_code'] ?? $metadata['payment_code'] ?? $metadata['qr_content'] ?? null;

        return ($gatewayPayment['status'] ?? null) === PaymentAttempt::StatusPending
            && is_string($gatewayPayment['expires_at'] ?? null)
            && ((is_string($checkoutUrl) && filter_var($checkoutUrl, FILTER_VALIDATE_URL))
                || (is_string($paymentCode) && $paymentCode !== ''));
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

    private function assertRetryAllowed(
        PaymentAttempt $attempt,
        Invoice $invoice,
        ?CheckoutOutboxMessage $outbox,
    ): void {
        if (! in_array($attempt->status, [PaymentAttempt::StatusInitiated, PaymentAttempt::StatusPending], true)) {
            throw ValidationException::withMessages([
                'payment_attempt' => ['Payment attempt dengan status saat ini tidak dapat diulang.'],
            ]);
        }

        if (strtolower((string) $attempt->provider) !== 'dana'
            || strtolower((string) $attempt->gateway_environment) !== 'sandbox'
            || ! is_string($attempt->gateway_merchant_code)
            || $attempt->gateway_merchant_code === ''
            || ! is_string($attempt->merchant_reference)
            || $attempt->merchant_reference === '') {
            throw ValidationException::withMessages([
                'payment_attempt' => ['Identitas checkout Sandbox tidak valid.'],
            ]);
        }

        $this->assertInvoiceCanStartCheckout($invoice);

        $expectedMerchantReference = $invoice->invoice_number.'-A'.$attempt->attempt_number;
        if ($attempt->merchant_reference !== $expectedMerchantReference
            || strtoupper((string) $attempt->currency) !== strtoupper((string) $invoice->currency)
            || $this->amountMinor($attempt->getRawOriginal('amount')) !== $this->amountMinor($invoice->getRawOriginal('amount'))) {
            throw ValidationException::withMessages([
                'payment_attempt' => ['Referensi, nominal, atau currency checkout tidak cocok dengan invoice.'],
            ]);
        }

        if ($invoice->plan === null) {
            throw ValidationException::withMessages([
                'payment_attempt' => ['Paket invoice tidak ditemukan untuk memvalidasi merchant.'],
            ]);
        }

        $mapping = ApplicationPaymentGateway::query()
            ->where('application_id', $invoice->plan->application_id)
            ->where('provider', 'dana')
            ->where('environment', 'sandbox')
            ->where('gateway_merchant_code', $attempt->gateway_merchant_code)
            ->where('is_active', true)
            ->first();

        if ($mapping === null || $mapping->environment !== $attempt->gateway_environment) {
            throw ValidationException::withMessages([
                'payment_attempt' => ['Mapping merchant checkout tidak lagi tersedia atau tidak cocok.'],
            ]);
        }

        if ($outbox === null || $outbox->idempotency_key !== 'dana-checkout-attempt-'.$attempt->getKey()) {
            throw ValidationException::withMessages([
                'payment_attempt' => ['Outbox checkout tidak ditemukan atau tidak valid.'],
            ]);
        }

        if (! in_array($outbox->status, [
            CheckoutOutboxMessage::StatusFailed,
            CheckoutOutboxMessage::StatusPending,
            CheckoutOutboxMessage::StatusProcessing,
            CheckoutOutboxMessage::StatusCompleted,
        ], true)) {
            throw ValidationException::withMessages([
                'payment_attempt' => ['Status outbox checkout tidak dapat diulang.'],
            ]);
        }
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
