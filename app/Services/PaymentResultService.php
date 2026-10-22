<?php

namespace App\Services;

use App\Models\PaymentAttempt;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class PaymentResultService
{
    public function __construct(
        private readonly DanaGatewayClient $gatewayClient,
        private readonly DanaGatewayMerchantResolver $merchantResolver,
        private readonly PaymentAttemptService $paymentAttemptService,
        private readonly InvoiceSettlementService $invoiceSettlementService,
    ) {}

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

        return $paymentAttempt->fresh('invoice.plan.application');
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
