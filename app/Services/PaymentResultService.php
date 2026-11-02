<?php

namespace App\Services;

use App\Models\PaymentAttempt;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class PaymentResultService
{
    private readonly MidtransGatewayProvisioningClient $midtransClient;
    private readonly MidtransSecretStore $midtransSecretStore;

    public function __construct(
        private readonly DanaGatewayClient $gatewayClient,
        private readonly DanaGatewayMerchantResolver $merchantResolver,
        private readonly PaymentAttemptService $paymentAttemptService,
        private readonly InvoiceSettlementService $invoiceSettlementService,
        ?MidtransGatewayProvisioningClient $midtransClient = null,
        ?MidtransSecretStore $midtransSecretStore = null,
    ) {
        $this->midtransClient = $midtransClient ?? app(MidtransGatewayProvisioningClient::class);
        $this->midtransSecretStore = $midtransSecretStore ?? app(MidtransSecretStore::class);
    }

    public function find(string $gatewayPaymentId): PaymentAttempt
    {
        $paymentAttempt = PaymentAttempt::query()
            ->with('invoice.plan.application')
            ->where('gateway_payment_id', $gatewayPaymentId)
            ->first();

        if ($paymentAttempt === null) {
            throw (new ModelNotFoundException)->setModel(PaymentAttempt::class);
        }

        if (! $this->isFinal($paymentAttempt->status)) {
            if (strtolower((string) $paymentAttempt->provider) === 'midtrans') {
                $paymentAttempt = $this->synchronizeMidtrans($paymentAttempt);
            } else {
                $merchant = $this->merchantResolver->resolve(
                    $paymentAttempt->gateway_merchant_code,
                    $paymentAttempt->gateway_environment,
                );
                $gatewayPayment = $this->gatewayClient->findPayment($merchant, $gatewayPaymentId);
                $this->validateGatewayPayment($paymentAttempt, $gatewayPayment);
                $paymentAttempt = $this->synchronize($paymentAttempt, $gatewayPayment);

                if (($gatewayPayment['status'] ?? null) === 'paid') {
                    $this->settleVerifiedPayment($paymentAttempt, $gatewayPayment);
                }
            }
        }

        return $paymentAttempt->fresh('invoice.plan.application');
    }

    private function synchronizeMidtrans(PaymentAttempt $paymentAttempt): PaymentAttempt
    {
        $credential = \App\Models\MidtransMerchantCredential::query()
            ->where('merchant_code', $paymentAttempt->gateway_merchant_code)
            ->where('environment', 'sandbox')
            ->first();

        if ($credential === null || empty($credential->encrypted_server_key)) {
            return $paymentAttempt;
        }

        $serverKey = $credential->getServerKey($this->midtransSecretStore);
        if (empty($serverKey)) {
            return $paymentAttempt;
        }
        $result = $this->midtransClient->querySandboxStatus(
            (string) $paymentAttempt->gateway_merchant_code,
            $serverKey,
            (string) $paymentAttempt->merchant_reference,
            'sandbox',
        );

        if (($result['status'] ?? '') !== 'success') {
            return $paymentAttempt;
        }

        $data = $result['data'] ?? [];
        $raw = $data['raw_response'] ?? [];
        $transactionStatus = (string) ($data['transaction_status'] ?? 'unknown');
        $fraudStatus = (string) ($raw['fraud_status'] ?? '');

        $isPaid = in_array($transactionStatus, ['settlement', 'capture'], true) && in_array($fraudStatus, ['', 'accept'], true);
        if ($isPaid) {
            $paidAt = ! empty($raw['settlement_time'])
                ? CarbonImmutable::parse($raw['settlement_time'])
                : (! empty($raw['transaction_time']) ? CarbonImmutable::parse($raw['transaction_time']) : now()->toImmutable());

            if ($paidAt->isFuture()) {
                $paidAt = now()->toImmutable();
            }

            $pendingAttempt = $paymentAttempt->status === PaymentAttempt::StatusInitiated
                ? $this->paymentAttemptService->transitionStatus($paymentAttempt, PaymentAttempt::StatusPending)
                : $paymentAttempt;

            $updatedAttempt = $this->paymentAttemptService->transitionStatus(
                $pendingAttempt,
                PaymentAttempt::StatusSucceeded,
                $raw['transaction_id'] ?? $paymentAttempt->gateway_payment_id,
                null,
                null,
                $paidAt,
            );

            $metadata = is_array($updatedAttempt->metadata) ? $updatedAttempt->metadata : [];
            $metadata['gateway_verification'] = [
                'provider' => 'midtrans',
                'gateway_payment_id' => $raw['transaction_id'] ?? $updatedAttempt->gateway_payment_id,
                'verified_at' => now()->toISOString(),
                'gateway_paid_at' => $paidAt->toISOString(),
            ];
            $updatedAttempt->update(['metadata' => $metadata]);

            $grossAmount = (int) round((float) ($raw['gross_amount'] ?? $paymentAttempt->amount));
            $this->invoiceSettlementService->settle(
                $updatedAttempt,
                VerifiedGatewayPayment::paid(
                    provider: 'midtrans',
                    gatewayPaymentId: (string) ($raw['transaction_id'] ?? $updatedAttempt->gateway_payment_id),
                    merchantOrderId: (string) $paymentAttempt->merchant_reference,
                    amountMinor: $grossAmount,
                    currency: (string) $paymentAttempt->currency,
                    merchantCode: (string) $updatedAttempt->gateway_merchant_code,
                    environment: (string) $updatedAttempt->gateway_environment,
                    paidAt: $paidAt,
                ),
            );

            return $updatedAttempt;
        }

        if (in_array($transactionStatus, ['expire', 'cancel', 'deny'], true)) {
            $status = match ($transactionStatus) {
                'expire', 'expired' => PaymentAttempt::StatusExpired,
                'cancel' => PaymentAttempt::StatusCancelled,
                default => PaymentAttempt::StatusFailed,
            };

            $txId = (string) ($data['transaction_id'] ?? $raw['transaction_id'] ?? $paymentAttempt->gateway_payment_id);

            $updatedAttempt = $this->paymentAttemptService->transitionStatus(
                $paymentAttempt,
                $status,
                providerReference: $txId ?: null,
                failureCode: 'midtrans_'.$transactionStatus,
                failureMessage: $data['status_message'] ?? 'Transaksi Midtrans telah kedaluwarsa atau dibatalkan.',
            );

            if ($txId !== '' && $updatedAttempt->gateway_payment_id !== $txId) {
                $updatedAttempt->update(['gateway_payment_id' => $txId]);
            }

            if ($updatedAttempt->checkoutOutbox !== null && $updatedAttempt->checkoutOutbox->status !== \App\Models\CheckoutOutboxMessage::StatusCompleted) {
                $updatedAttempt->checkoutOutbox->update([
                    'status' => \App\Models\CheckoutOutboxMessage::StatusFailed,
                    'locked_at' => null,
                    'available_at' => null,
                    'error_code' => 'transaction_'.$transactionStatus,
                ]);
            }

            return $updatedAttempt;
        }

        return $paymentAttempt;
    }

    /** @param array<string, mixed> $gatewayPayment */
    private function synchronize(PaymentAttempt $paymentAttempt, array $gatewayPayment): PaymentAttempt
    {
        $gatewayStatus = Arr::get($gatewayPayment, 'status');
        $status = match ($gatewayStatus) {
            'pending' => PaymentAttempt::StatusPending,
            'paid' => PaymentAttempt::StatusSucceeded,
            'failed' => PaymentAttempt::StatusFailed,
            'expired' => PaymentAttempt::StatusExpired,
            'cancelled' => PaymentAttempt::StatusCancelled,
            default => throw new DanaGatewayException('Status pembayaran gateway tidak dikenali.', false),
        };

        if ($paymentAttempt->status === $status) {
            return $paymentAttempt;
        }

        $completedAt = $status === PaymentAttempt::StatusSucceeded
            ? $this->gatewayTimestamp($gatewayPayment['paid_at'] ?? null)
            : null;

        $updatedAttempt = $this->paymentAttemptService->transitionStatus(
            $paymentAttempt,
            $status,
            $this->stringOrNull($gatewayPayment['provider_order_id'] ?? null),
            $status === PaymentAttempt::StatusFailed ? 'gateway_failed' : null,
            null,
            $completedAt,
        );

        if ($status === PaymentAttempt::StatusSucceeded) {
            $metadata = is_array($updatedAttempt->metadata) ? $updatedAttempt->metadata : [];
            $metadata['gateway_verification'] = [
                'provider' => $updatedAttempt->provider,
                'gateway_payment_id' => $updatedAttempt->gateway_payment_id,
                'verified_at' => now()->toISOString(),
                'gateway_paid_at' => $gatewayPayment['paid_at'] ?? null,
            ];
            $updatedAttempt->update(['metadata' => $metadata]);
        }

        return $updatedAttempt;
    }

    /** @param array<string, mixed> $gatewayPayment */
    private function settleVerifiedPayment(PaymentAttempt $paymentAttempt, array $gatewayPayment): void
    {
        $paidAt = $this->gatewayTimestamp($gatewayPayment['paid_at'] ?? null);
        if ($paidAt === null) {
            return;
        }

        try {
            $this->invoiceSettlementService->settle(
                $paymentAttempt,
                VerifiedGatewayPayment::paid(
                    provider: $paymentAttempt->provider,
                    gatewayPaymentId: (string) $gatewayPayment['id'],
                    merchantOrderId: (string) $gatewayPayment['merchant_order_id'],
                    amountMinor: (int) $gatewayPayment['amount_minor'],
                    currency: (string) $gatewayPayment['currency'],
                    merchantCode: (string) $paymentAttempt->gateway_merchant_code,
                    environment: (string) $paymentAttempt->gateway_environment,
                    paidAt: $paidAt,
                ),
            );
        } catch (SettlementRejectedException|ValidationException) {
            // Status publik tetap berasal dari gateway terverifikasi; settlement ambigu
            // dicatat oleh engine untuk pemulihan tanpa mengubahnya menjadi lunas lokal.
        }
    }

    /** @param array<string, mixed> $gatewayPayment */
    private function validateGatewayPayment(PaymentAttempt $paymentAttempt, array $gatewayPayment): void
    {
        $expectedAmount = $this->amountMinor((string) $paymentAttempt->getRawOriginal('amount'));

        if (($gatewayPayment['id'] ?? null) !== $paymentAttempt->gateway_payment_id
            || ($gatewayPayment['merchant_order_id'] ?? null) !== $paymentAttempt->merchant_reference
            || ($gatewayPayment['amount_minor'] ?? null) !== $expectedAmount
            || ($gatewayPayment['currency'] ?? null) !== $paymentAttempt->currency) {
            throw new DanaGatewayException('Data status pembayaran gateway tidak cocok.', false);
        }
    }

    private function gatewayTimestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function isFinal(string $status): bool
    {
        return in_array($status, [
            PaymentAttempt::StatusSucceeded,
            PaymentAttempt::StatusFailed,
            PaymentAttempt::StatusExpired,
            PaymentAttempt::StatusCancelled,
        ], true);
    }

    private function amountMinor(string $amount): int
    {
        if (preg_match('/^([1-9][0-9]*)(?:\.0{1,2})?$/', $amount, $matches) !== 1) {
            throw new DanaGatewayException('Nominal pembayaran tersimpan tidak valid.', false);
        }

        return (int) $matches[1];
    }
}
