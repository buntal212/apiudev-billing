<?php

namespace App\Services;

use App\Models\PaymentAttempt;
use App\Models\PaymentReconciliationRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaymentReconciliationService
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

    public function reconcile(PaymentAttempt $paymentAttempt, bool $force = false): ReconciliationResult
    {
        $attempt = $paymentAttempt->fresh();
        if ($attempt === null || ! $this->isSupported($attempt)) {
            return new ReconciliationResult('skipped', $attempt ?? $paymentAttempt);
        }

        $run = $this->claimRun($attempt, $force);
        if ($run === null) {
            return new ReconciliationResult('skipped', $attempt->fresh() ?? $attempt);
        }

        try {
            if (strtolower((string) $attempt->provider) === 'midtrans') {
                return $this->reconcileMidtrans($attempt, $run);
            }

            $merchant = $this->merchantResolver->resolve(
                (string) $attempt->gateway_merchant_code,
                (string) $attempt->gateway_environment,
            );
            $gatewayPayment = $this->gatewayClient->findPayment($merchant, (string) $attempt->gateway_payment_id);
            $this->validateGatewayPayment($attempt, $gatewayPayment);

            return $this->applyGatewayResult($attempt, $run, $gatewayPayment);
        } catch (DanaGatewayException $exception) {
            return $this->handleGatewayException($run, $attempt, $exception);
        } catch (ConnectionException) {
            return $this->finishRetryable($run, $attempt, 'gateway_timeout');
        } catch (SettlementRejectedException $exception) {
            return $this->finishFailed($run, $attempt, $exception->safeCode);
        } catch (ValidationException) {
            return $this->finishFailed($run, $attempt, 'payment_attempt_transition_invalid');
        } catch (Throwable) {
            return $this->finishFailed($run, $attempt, 'reconciliation_processing_failed');
        }
    }

    public function reconcilePending(?int $limit = null): int
    {
        $limit = max(1, min(100, $limit ?? (int) config('services.dana_reconciliation.batch_limit', 25)));
        $now = now()->toImmutable();
        $baseQuery = fn (): Builder => PaymentAttempt::query()
            ->whereIn(DB::raw('LOWER(provider)'), ['dana', 'midtrans'])
            ->where('gateway_environment', 'sandbox')
            ->where(function (Builder $query): void {
                $query->whereNotNull('gateway_payment_id')
                    ->orWhereNotNull('merchant_reference');
            })
            ->whereIn('status', [PaymentAttempt::StatusInitiated, PaymentAttempt::StatusPending]);

        // Prioritaskan attempt yang belum pernah direkonsiliasi agar backlog lama
        // tidak menghalangi transaksi baru ketika batch terbatas.
        $newAttemptIds = $baseQuery()
            ->whereDoesntHave('reconciliationRuns')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $attemptIds = $newAttemptIds;
        $remaining = $limit - $newAttemptIds->count();
        if ($remaining > 0) {
            $retryAttemptIds = $baseQuery()
                ->whereHas('latestReconciliationRun', function (Builder $query) use ($now): void {
                    $query->where('attempt_number', '<', $this->maxAttempts());
                    $this->scopeDueRun($query, $now);
                })
                ->orderBy('id')
                ->limit($remaining)
                ->pluck('id');

            $attemptIds = $attemptIds->concat($retryAttemptIds)->take($limit);
        }

        foreach ($attemptIds as $attemptId) {
            $attempt = PaymentAttempt::query()->find($attemptId);
            if ($attempt !== null) {
                $this->reconcile($attempt);
            }
        }

        return $attemptIds->count();
    }

    private function applyGatewayResult(
        PaymentAttempt $attempt,
        PaymentReconciliationRun $run,
        array $gatewayPayment,
    ): ReconciliationResult {
        $gatewayStatus = Arr::get($gatewayPayment, 'status');
        $run->gateway_status = is_string($gatewayStatus) ? $gatewayStatus : null;

        return match ($gatewayStatus) {
            'pending' => $this->finishPending($run, $attempt),
            'paid' => $this->finishPaid($run, $attempt, $gatewayPayment),
            'failed', 'expired', 'cancelled' => $this->finishTerminal($run, $attempt, (string) $gatewayStatus),
            default => $this->finishRetryable($run, $attempt, 'gateway_status_unknown'),
        };
    }

    /** @param array<string, mixed> $gatewayPayment */
    private function finishPaid(
        PaymentReconciliationRun $run,
        PaymentAttempt $attempt,
        array $gatewayPayment,
    ): ReconciliationResult {
        $paidAt = $this->gatewayTimestamp($gatewayPayment['paid_at'] ?? null);
        if ($paidAt === null) {
            return $this->finishRetryable($run, $attempt, 'gateway_paid_timestamp_missing');
        }
        if ($paidAt->isFuture()) {
            return $this->finishRetryable($run, $attempt, 'gateway_paid_timestamp_invalid');
        }

        $pendingAttempt = $attempt->status === PaymentAttempt::StatusInitiated
            ? $this->paymentAttemptService->transitionStatus($attempt, PaymentAttempt::StatusPending)
            : $attempt;
        $updatedAttempt = $this->paymentAttemptService->transitionStatus(
            $pendingAttempt,
            PaymentAttempt::StatusSucceeded,
            $this->stringOrNull($gatewayPayment['provider_order_id'] ?? null),
            null,
            null,
            $paidAt,
        );
        $metadata = is_array($updatedAttempt->metadata) ? $updatedAttempt->metadata : [];
        $metadata['gateway_verification'] = [
            'provider' => $updatedAttempt->provider,
            'gateway_payment_id' => $updatedAttempt->gateway_payment_id,
            'verified_at' => now()->toISOString(),
            'gateway_paid_at' => $paidAt->toISOString(),
        ];
        $updatedAttempt->update(['metadata' => $metadata]);

        $this->invoiceSettlementService->settle(
            $updatedAttempt,
            VerifiedGatewayPayment::paid(
                provider: $updatedAttempt->provider,
                gatewayPaymentId: (string) $gatewayPayment['id'],
                merchantOrderId: (string) $gatewayPayment['merchant_order_id'],
                amountMinor: (int) $gatewayPayment['amount_minor'],
                currency: (string) $gatewayPayment['currency'],
                merchantCode: (string) $updatedAttempt->gateway_merchant_code,
                environment: (string) $updatedAttempt->gateway_environment,
                paidAt: $paidAt,
            ),
        );

        return $this->finishRun(
            $run,
            $updatedAttempt,
            PaymentReconciliationRun::StatusPaid,
            verifiedAt: $paidAt,
        );
    }

    private function finishPending(PaymentReconciliationRun $run, PaymentAttempt $attempt): ReconciliationResult
    {
        $updatedAttempt = $attempt;
        if ($attempt->status === PaymentAttempt::StatusInitiated) {
            $updatedAttempt = $this->paymentAttemptService->transitionStatus(
                $attempt,
                PaymentAttempt::StatusPending,
            );
        }

        return $this->finishRun(
            $run,
            $updatedAttempt,
            PaymentReconciliationRun::StatusPending,
            retryAfterAt: $this->nextRetryAt($run->attempt_number),
        );
    }

    private function finishTerminal(
        PaymentReconciliationRun $run,
        PaymentAttempt $attempt,
        string $gatewayStatus,
    ): ReconciliationResult {
        $status = match ($gatewayStatus) {
            'failed' => PaymentAttempt::StatusFailed,
            'expired' => PaymentAttempt::StatusExpired,
            default => PaymentAttempt::StatusCancelled,
        };
        $updatedAttempt = $attempt->status === $status
            ? $attempt
            : $this->paymentAttemptService->transitionStatus(
                $attempt,
                $status,
                failureCode: 'gateway_'.$gatewayStatus,
            );

        return $this->finishRun(
            $run,
            $updatedAttempt,
            PaymentReconciliationRun::StatusTerminal,
        );
    }

    private function handleGatewayException(
        PaymentReconciliationRun $run,
        PaymentAttempt $attempt,
        DanaGatewayException $exception,
    ): ReconciliationResult {
        if (! $exception->retryable) {
            return $this->finishFailed($run, $attempt, $exception->errorCode, $exception->statusCode);
        }

        return $this->finishRetryable(
            $run,
            $attempt,
            $exception->errorCode,
            $exception->statusCode,
            $exception->retryAfterSeconds,
        );
    }

    private function finishRetryable(
        PaymentReconciliationRun $run,
        PaymentAttempt $attempt,
        string $errorCode,
        ?int $httpStatus = null,
        ?int $retryAfterSeconds = null,
    ): ReconciliationResult {
        if ($run->attempt_number >= $this->maxAttempts()) {
            return $this->finishRun(
                $run,
                $attempt,
                PaymentReconciliationRun::StatusFailed,
                errorCode: 'reconciliation_retry_exhausted',
                httpStatus: $httpStatus,
            );
        }

        $retryAfterAt = $this->nextRetryAt($run->attempt_number, $retryAfterSeconds);

        return $this->finishRun(
            $run,
            $attempt,
            PaymentReconciliationRun::StatusRetryable,
            errorCode: $errorCode,
            httpStatus: $httpStatus,
            retryAfterAt: $retryAfterAt,
        );
    }

    private function finishFailed(
        PaymentReconciliationRun $run,
        PaymentAttempt $attempt,
        string $errorCode,
        ?int $httpStatus = null,
    ): ReconciliationResult {
        return $this->finishRun(
            $run,
            $attempt,
            PaymentReconciliationRun::StatusFailed,
            errorCode: $errorCode,
            httpStatus: $httpStatus,
        );
    }

    private function finishRun(
        PaymentReconciliationRun $run,
        PaymentAttempt $attempt,
        string $status,
        ?string $errorCode = null,
        ?int $httpStatus = null,
        ?CarbonImmutable $retryAfterAt = null,
        ?CarbonImmutable $verifiedAt = null,
    ): ReconciliationResult {
        $run->update([
            'status' => $status,
            'gateway_status' => $run->gateway_status,
            'error_code' => $errorCode,
            'http_status' => $httpStatus,
            'retry_after_at' => $retryAfterAt,
            'verified_at' => $verifiedAt,
            'finished_at' => now(),
        ]);

        return new ReconciliationResult(
            $status,
            $attempt->fresh('invoice') ?? $attempt,
            $run->fresh(),
            $retryAfterAt,
        );
    }

    private function claimRun(PaymentAttempt $paymentAttempt, bool $force = false): ?PaymentReconciliationRun
    {
        try {
            return DB::transaction(function () use ($paymentAttempt, $force): ?PaymentReconciliationRun {
                $attempt = PaymentAttempt::query()->lockForUpdate()->find($paymentAttempt->getKey());
                if ($attempt === null || $this->isFinal($attempt->status)) {
                    return null;
                }

                $latest = PaymentReconciliationRun::query()
                    ->where('payment_attempt_id', $attempt->getKey())
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();

                if ($latest !== null) {
                    $now = now()->toImmutable();
                    $processingTimeout = $force ? 15 : $this->processingTimeoutSeconds();
                    // If stuck in processing past the timeout, mark stale run as failed
                    if ($latest->status === PaymentReconciliationRun::StatusProcessing
                        && ($latest->started_at === null || $latest->started_at->lte($now->subSeconds($processingTimeout)))) {
                        $latest->update([
                            'status' => PaymentReconciliationRun::StatusFailed,
                            'error_code' => 'reconciliation_stale_timeout',
                            'finished_at' => now(),
                        ]);
                        $latest = $latest->fresh();
                    }

                    if ($latest->attempt_number >= $this->maxAttempts()) {
                        return null;
                    }

                    if (! $force && ! $this->isRunDue($latest, $now)) {
                        return null;
                    }
                }

                $attemptNumber = ($latest?->attempt_number ?? 0) + 1;

                return PaymentReconciliationRun::query()->create([
                    'payment_attempt_id' => $attempt->getKey(),
                    'attempt_number' => $attemptNumber,
                    'status' => PaymentReconciliationRun::StatusProcessing,
                    'idempotency_key' => 'reconciliation:payment-attempt:'.$attempt->getKey().':try:'.$attemptNumber,
                    'started_at' => now(),
                ]);
            });
        } catch (QueryException) {
            return null;
        }
    }

    private function scopeDueRun(Builder $query, CarbonImmutable $now): void
    {
        $query->where(function (Builder $query) use ($now): void {
            $query
                ->where(function (Builder $query) use ($now): void {
                    $query
                        ->whereIn('status', [
                            PaymentReconciliationRun::StatusPending,
                            PaymentReconciliationRun::StatusRetryable,
                        ])
                        ->where(function (Builder $query) use ($now): void {
                            $query->whereNull('retry_after_at')
                                ->orWhere('retry_after_at', '<=', $now);
                        });
                })
                ->orWhere(function (Builder $query) use ($now): void {
                    $query
                        ->where('status', PaymentReconciliationRun::StatusProcessing)
                        ->where(function (Builder $query) use ($now): void {
                            $query->whereNull('started_at')
                                ->orWhere('started_at', '<=', $now->subSeconds($this->processingTimeoutSeconds()));
                        });
                });
        });
    }

    private function isRunDue(PaymentReconciliationRun $run, CarbonImmutable $now): bool
    {
        return match ($run->status) {
            PaymentReconciliationRun::StatusPending,
            PaymentReconciliationRun::StatusRetryable => $run->retry_after_at === null
                || ! $run->retry_after_at->isFuture(),
            PaymentReconciliationRun::StatusProcessing => $run->started_at === null
                || ! $run->started_at->gt($now->subSeconds($this->processingTimeoutSeconds())),
            default => false,
        };
    }

    /** @param array<string, mixed> $gatewayPayment */
    private function validateGatewayPayment(PaymentAttempt $attempt, array $gatewayPayment): void
    {
        $expectedAmount = $this->amountMinor((string) $attempt->getRawOriginal('amount'));
        if (($gatewayPayment['id'] ?? null) !== $attempt->gateway_payment_id
            || ($gatewayPayment['merchant_order_id'] ?? null) !== $attempt->merchant_reference
            || ($gatewayPayment['amount_minor'] ?? null) !== $expectedAmount
            || strtoupper((string) ($gatewayPayment['currency'] ?? '')) !== strtoupper((string) $attempt->currency)) {
            throw new DanaGatewayException('Data status pembayaran gateway tidak cocok.', false, null, null, 'gateway_identity_or_amount_mismatch');
        }
    }

    private function reconcileMidtrans(PaymentAttempt $attempt, PaymentReconciliationRun $run): ReconciliationResult
    {
        $credential = \App\Models\MidtransMerchantCredential::query()
            ->where('merchant_code', $attempt->gateway_merchant_code)
            ->where('environment', 'sandbox')
            ->first();

        if ($credential === null || empty($credential->encrypted_server_key)) {
            return $this->finishFailed($run, $attempt, 'midtrans_merchant_not_configured');
        }

        $serverKey = $credential->getServerKey($this->midtransSecretStore);
        if (empty($serverKey)) {
            return $this->finishFailed($run, $attempt, 'midtrans_merchant_not_configured');
        }
        $statusResult = $this->midtransClient->querySandboxStatus(
            (string) $attempt->gateway_merchant_code,
            $serverKey,
            (string) $attempt->merchant_reference,
            'sandbox',
        );

        if (($statusResult['status'] ?? '') !== 'success') {
            $isNotFound = ($statusResult['is_not_found'] ?? false) || ($statusResult['http_status'] ?? 0) === 404;
            if ($isNotFound) {
                $run->gateway_status = 'not_found';
                try {
                    app(MidtransAuditService::class)->record(
                        merchantCode: (string) $attempt->gateway_merchant_code,
                        action: 'sandbox.reconciliation_not_found',
                        status: 'failed',
                        responseCode: '404',
                        metadata: [
                            'order_id' => $attempt->merchant_reference,
                            'payment_attempt_id' => $attempt->id,
                            'message' => 'Transaksi tidak ditemukan di Midtrans Sandbox.',
                        ],
                    );
                } catch (\Throwable) {
                    // ignore audit failure
                }

                return $this->finishFailed($run, $attempt, 'midtrans_order_not_found', 404);
            }

            try {
                app(MidtransAuditService::class)->record(
                    merchantCode: (string) $attempt->gateway_merchant_code,
                    action: 'sandbox.reconciliation_query_failed',
                    status: 'failed',
                    responseCode: (string) ($statusResult['http_status'] ?? '500'),
                    metadata: [
                        'order_id' => $attempt->merchant_reference,
                        'payment_attempt_id' => $attempt->id,
                        'message' => $statusResult['message'] ?? 'Gagal query status Midtrans.',
                    ],
                );
            } catch (\Throwable) {
                // ignore audit failure
            }

            return $this->finishRetryable($run, $attempt, 'midtrans_query_failed');
        }

        $data = $statusResult['data'] ?? [];
        $raw = $data['raw_response'] ?? [];
        $transactionStatus = (string) ($data['transaction_status'] ?? 'unknown');
        $fraudStatus = (string) ($raw['fraud_status'] ?? '');
        $run->gateway_status = $transactionStatus;

        if (in_array($transactionStatus, ['settlement', 'capture'], true) && in_array($fraudStatus, ['', 'accept'], true)) {
            $paidAt = ! empty($raw['settlement_time'])
                ? CarbonImmutable::parse($raw['settlement_time'])
                : (! empty($raw['transaction_time']) ? CarbonImmutable::parse($raw['transaction_time']) : now()->toImmutable());

            if ($paidAt->isFuture()) {
                $paidAt = now()->toImmutable();
            }

            $pendingAttempt = $attempt->status === PaymentAttempt::StatusInitiated
                ? $this->paymentAttemptService->transitionStatus($attempt, PaymentAttempt::StatusPending)
                : $attempt;

            $updatedAttempt = $this->paymentAttemptService->transitionStatus(
                $pendingAttempt,
                PaymentAttempt::StatusSucceeded,
                $raw['transaction_id'] ?? $attempt->gateway_payment_id,
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

            $grossAmount = (int) round((float) ($raw['gross_amount'] ?? $attempt->amount));
            $this->invoiceSettlementService->settle(
                $updatedAttempt,
                VerifiedGatewayPayment::paid(
                    provider: 'midtrans',
                    gatewayPaymentId: (string) ($raw['transaction_id'] ?? $updatedAttempt->gateway_payment_id),
                    merchantOrderId: (string) $attempt->merchant_reference,
                    amountMinor: $grossAmount,
                    currency: (string) $attempt->currency,
                    merchantCode: (string) $updatedAttempt->gateway_merchant_code,
                    environment: (string) $updatedAttempt->gateway_environment,
                    paidAt: $paidAt,
                ),
            );

            try {
                app(MidtransAuditService::class)->record(
                    merchantCode: (string) $attempt->gateway_merchant_code,
                    action: 'sandbox.reconciliation_settled',
                    status: 'success',
                    responseCode: '200',
                    metadata: [
                        'order_id' => $attempt->merchant_reference,
                        'payment_attempt_id' => $attempt->id,
                        'transaction_id' => $raw['transaction_id'] ?? null,
                    ],
                );
            } catch (\Throwable) {
                // ignore audit failure
            }

            return $this->finishRun(
                $run,
                $updatedAttempt,
                PaymentReconciliationRun::StatusPaid,
                verifiedAt: $paidAt,
            );
        }

        if (in_array($transactionStatus, ['expire', 'cancel', 'deny'], true)) {
            try {
                app(MidtransAuditService::class)->record(
                    merchantCode: (string) $attempt->gateway_merchant_code,
                    action: 'sandbox.reconciliation_terminal',
                    status: 'processed',
                    responseCode: '200',
                    metadata: [
                        'order_id' => $attempt->merchant_reference,
                        'payment_attempt_id' => $attempt->id,
                        'gateway_status' => $transactionStatus,
                    ],
                );
            } catch (\Throwable) {
                // ignore audit failure
            }

            return $this->finishTerminal($run, $attempt, $transactionStatus);
        }

        if ($transactionStatus === 'pending') {
            $metadata = is_array($attempt->metadata) ? $attempt->metadata : [];
            $paymentType = $metadata['payment_type'] ?? ($attempt->provider === 'midtrans' ? 'qris' : null);
            $txId = $raw['transaction_id'] ?? $attempt->gateway_payment_id;

            if ($paymentType === 'qris' && ! empty($txId)) {
                $recoveredQrUrl = "https://api.sandbox.midtrans.com/v2/qris/{$txId}/qr-code";
                $updated = false;
                if (empty($metadata['qr_url'])) {
                    $metadata['qr_url'] = $recoveredQrUrl;
                    $updated = true;
                }
                if (empty($attempt->checkout_url)) {
                    $attempt->checkout_url = $recoveredQrUrl;
                    $updated = true;
                }
                if (empty($attempt->provider_reference)) {
                    $attempt->provider_reference = $txId;
                    $updated = true;
                }
                if ($updated) {
                    $attempt->metadata = $metadata;
                    $attempt->save();
                }
            }

            try {
                app(MidtransAuditService::class)->record(
                    merchantCode: (string) $attempt->gateway_merchant_code,
                    action: 'sandbox.reconciliation_pending',
                    status: 'pending',
                    responseCode: '200',
                    metadata: [
                        'order_id' => $attempt->merchant_reference,
                        'payment_attempt_id' => $attempt->id,
                        'transaction_id' => $txId,
                    ],
                );
            } catch (\Throwable) {
                // ignore audit failure
            }

            return $this->finishPending($run, $attempt);
        }

        return $this->finishRetryable($run, $attempt, 'gateway_status_unknown');
    }

    private function isSupported(PaymentAttempt $attempt): bool
    {
        $hasIdentifier = (is_string($attempt->gateway_payment_id) && $attempt->gateway_payment_id !== '')
            || (is_string($attempt->merchant_reference) && $attempt->merchant_reference !== '');

        return in_array(strtolower((string) $attempt->provider), ['dana', 'midtrans'], true)
            && $attempt->gateway_environment === 'sandbox'
            && $hasIdentifier
            && in_array($attempt->status, [PaymentAttempt::StatusInitiated, PaymentAttempt::StatusPending], true);
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

    private function gatewayTimestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function amountMinor(string $amount): int
    {
        if (preg_match('/^([1-9][0-9]*)(?:\.0{1,2})?$/', $amount, $matches) !== 1) {
            throw new DanaGatewayException('Nominal pembayaran tersimpan tidak valid.', false, null, null, 'payment_amount_invalid');
        }

        return (int) $matches[1];
    }

    private function nextRetryAt(int $attemptNumber, ?int $retryAfterSeconds = null): CarbonImmutable
    {
        $max = max(1, (int) config('services.dana_reconciliation.max_backoff_seconds', 900));
        if ($retryAfterSeconds !== null) {
            return now()->toImmutable()->addSeconds(min($max, max(0, $retryAfterSeconds)));
        }

        $base = max(1, (int) config('services.dana_reconciliation.base_backoff_seconds', 15));
        $backoff = min($max, $base * (2 ** max(0, $attemptNumber - 1)));

        return now()->toImmutable()->addSeconds($backoff);
    }

    private function maxAttempts(): int
    {
        return max(1, min(20, (int) config('services.dana_reconciliation.max_attempts', 5)));
    }

    private function processingTimeoutSeconds(): int
    {
        return max(30, (int) config('services.dana_reconciliation.processing_timeout_seconds', 120));
    }
}
