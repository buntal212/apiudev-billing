<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CheckoutOutboxMessage;
use App\Models\MidtransMerchantCredential;
use App\Models\PaymentAttempt;
use App\Services\InvoiceSettlementService;
use App\Services\MidtransAuditService;
use App\Services\MidtransEnvironmentService;
use App\Services\MidtransSecretStore;
use App\Services\PaymentAttemptService;
use App\Services\ReferralCommissionService;
use App\Services\VerifiedGatewayPayment;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MidtransWebhookController extends Controller
{
    public function __construct(
        private readonly MidtransSecretStore $secretStore,
        private readonly MidtransAuditService $auditService,
        private readonly PaymentAttemptService $paymentAttemptService,
        private readonly InvoiceSettlementService $invoiceSettlementService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->all();

        $orderId = (string) ($payload['order_id'] ?? '');
        $statusCode = (string) ($payload['status_code'] ?? '');
        $grossAmount = (string) ($payload['gross_amount'] ?? '');
        $signatureKey = (string) ($payload['signature_key'] ?? '');
        $transactionStatus = (string) ($payload['transaction_status'] ?? '');
        $fraudStatus = (string) ($payload['fraud_status'] ?? '');
        $transactionId = (string) ($payload['transaction_id'] ?? '');

        if ($orderId === '' || $signatureKey === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Format payload webhook Midtrans tidak valid.',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Find payment attempt to isolate merchant
        $attempt = PaymentAttempt::query()
            ->with('invoice')
            ->where('merchant_reference', $orderId)
            ->orWhere('gateway_payment_id', $transactionId)
            ->first();

        $merchantCode = $attempt?->gateway_merchant_code;
        $environment = $attempt?->gateway_environment
            ?: app(MidtransEnvironmentService::class)->getActiveEnvironment();

        $credential = null;
        if ($merchantCode !== null && $merchantCode !== '') {
            $credential = MidtransMerchantCredential::query()
                ->where('merchant_code', $merchantCode)
                ->where('environment', $environment)
                ->first();
        }

        if ($credential === null) {
            $credential = MidtransMerchantCredential::query()
                ->where('environment', $environment)
                ->where('is_active', true)
                ->first();
        }

        // Fallback: if not found in target environment, check sandbox
        if ($credential === null && $environment !== 'sandbox') {
            $credential = MidtransMerchantCredential::query()
                ->where('merchant_code', $merchantCode)
                ->where('environment', 'sandbox')
                ->first();
        }

        if ($credential === null || empty($credential->encrypted_server_key)) {
            $this->auditService->record(
                action: 'webhook.rejected_no_server_key',
                status: 'failed',
                merchantCode: $merchantCode,
                metadata: ['order_id' => $orderId, 'transaction_id' => $transactionId]
            );

            return response()->json([
                'status' => 'error',
                'message' => 'Server key untuk merchant Midtrans tidak ditemukan.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $serverKey = $credential->getServerKey($this->secretStore);
        if (empty($serverKey)) {
            $this->auditService->record(
                action: 'webhook.rejected_no_server_key',
                status: 'failed',
                merchantCode: $merchantCode,
                metadata: ['order_id' => $orderId, 'transaction_id' => $transactionId]
            );

            return response()->json([
                'status' => 'error',
                'message' => 'Server key untuk merchant Midtrans tidak dapat didekripsi.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Verify SHA512 signature: SHA512(order_id + status_code + gross_amount + ServerKey)
        $expectedSignature = hash('sha512', $orderId.$statusCode.$grossAmount.$serverKey);
        $isSignatureValid = hash_equals($expectedSignature, $signatureKey);

        $authHeader = (string) $request->header('Authorization');
        $serviceToken = (string) config('services.midtrans_gateway.service_token');
        $isInternalServiceAuthorized = ($serviceToken !== '' && hash_equals('Bearer '.$serviceToken, $authHeader));

        if (! $isSignatureValid && ! $isInternalServiceAuthorized) {
            $this->auditService->record(
                action: 'webhook.invalid_signature',
                status: 'failed',
                merchantCode: $credential->merchant_code,
                metadata: ['order_id' => $orderId, 'signature_key' => $signatureKey]
            );

            return response()->json([
                'status' => 'error',
                'message' => 'Signature Midtrans tidak valid.',
            ], Response::HTTP_FORBIDDEN);
        }

        if ($attempt === null) {
            $this->auditService->record(
                action: 'webhook.skipped_unknown_order',
                status: 'skipped',
                merchantCode: $credential->merchant_code,
                metadata: ['order_id' => $orderId, 'transaction_status' => $transactionStatus]
            );

            return response()->json([
                'status' => 'OK',
                'message' => 'Payment attempt tidak ditemukan untuk order_id ini. Webhook dilewati.',
                'order_id' => $orderId,
            ]);
        }

        $isPaid = in_array($transactionStatus, ['settlement', 'capture'], true)
            && in_array($fraudStatus, ['', 'accept'], true);

        if ($isPaid) {
            $paidAt = ! empty($payload['settlement_time'])
                ? CarbonImmutable::parse($payload['settlement_time'])
                : (! empty($payload['transaction_time']) ? CarbonImmutable::parse($payload['transaction_time']) : now()->toImmutable());

            if ($paidAt->isFuture()) {
                $paidAt = now()->toImmutable();
            }

            if ($attempt->status !== PaymentAttempt::StatusSucceeded) {
                $pendingAttempt = $attempt->status === PaymentAttempt::StatusInitiated
                    ? $this->paymentAttemptService->transitionStatus($attempt, PaymentAttempt::StatusPending)
                    : $attempt;

                $updatedAttempt = $this->paymentAttemptService->transitionStatus(
                    $pendingAttempt,
                    PaymentAttempt::StatusSucceeded,
                    $transactionId ?: $attempt->gateway_payment_id,
                    null,
                    null,
                    $paidAt,
                );

                $metadata = is_array($updatedAttempt->metadata) ? $updatedAttempt->metadata : [];
                $metadata['gateway_verification'] = [
                    'provider' => 'midtrans',
                    'gateway_payment_id' => $transactionId ?: $updatedAttempt->gateway_payment_id,
                    'verified_at' => now()->toISOString(),
                    'gateway_paid_at' => $paidAt->toISOString(),
                    'transaction_status' => $transactionStatus,
                    'fraud_status' => $fraudStatus,
                ];
                $updatedAttempt->update(['metadata' => $metadata]);
            } else {
                $updatedAttempt = $attempt;
            }

            $grossAmountMinor = (int) round((float) $grossAmount);
            $this->invoiceSettlementService->settle(
                $updatedAttempt,
                VerifiedGatewayPayment::paid(
                    provider: 'midtrans',
                    gatewayPaymentId: (string) ($transactionId ?: $updatedAttempt->gateway_payment_id),
                    merchantOrderId: (string) $attempt->merchant_reference,
                    amountMinor: $grossAmountMinor,
                    currency: (string) $attempt->currency,
                    merchantCode: (string) $attempt->gateway_merchant_code,
                    environment: (string) $attempt->gateway_environment,
                    paidAt: $paidAt,
                ),
            );

            $this->auditService->record(
                action: 'webhook.settled',
                status: 'success',
                merchantCode: $attempt->gateway_merchant_code,
                metadata: [
                    'order_id' => $orderId,
                    'transaction_status' => $transactionStatus,
                    'transaction_id' => $transactionId,
                    'invoice_id' => $attempt->invoice_id,
                ]
            );

            return response()->json([
                'status' => 'OK',
                'message' => 'Webhook berhasil diverifikasi dan invoice telah lunas.',
                'order_id' => $orderId,
                'transaction_status' => $transactionStatus,
            ]);
        }

        if (in_array($transactionStatus, ['expire', 'cancel', 'deny'], true)) {
            $status = match ($transactionStatus) {
                'expire', 'expired' => PaymentAttempt::StatusExpired,
                'cancel' => PaymentAttempt::StatusCancelled,
                default => PaymentAttempt::StatusFailed,
            };

            if (! in_array($attempt->status, [PaymentAttempt::StatusSucceeded, $status], true)) {
                $updatedAttempt = $this->paymentAttemptService->transitionStatus(
                    $attempt,
                    $status,
                    providerReference: $transactionId ?: $attempt->gateway_payment_id,
                    failureCode: 'midtrans_'.$transactionStatus,
                    failureMessage: (string) ($payload['status_message'] ?? 'Transaksi Midtrans telah kedaluwarsa atau dibatalkan.'),
                );

                if (! empty($transactionId) && $updatedAttempt->gateway_payment_id !== $transactionId) {
                    $updatedAttempt->update(['gateway_payment_id' => $transactionId]);
                }

                if ($updatedAttempt->checkoutOutbox !== null && $updatedAttempt->checkoutOutbox->status !== CheckoutOutboxMessage::StatusCompleted) {
                    $updatedAttempt->checkoutOutbox->update([
                        'status' => CheckoutOutboxMessage::StatusFailed,
                        'locked_at' => null,
                        'available_at' => null,
                        'error_code' => 'transaction_'.$transactionStatus,
                    ]);
                }
            }

            $this->auditService->record(
                action: 'webhook.terminal_status',
                status: 'processed',
                merchantCode: $attempt->gateway_merchant_code,
                metadata: [
                    'order_id' => $orderId,
                    'transaction_status' => $transactionStatus,
                    'target_status' => $status,
                ]
            );

            return response()->json([
                'status' => 'OK',
                'message' => 'Status pembayaran terminal berhasil diperbarui.',
                'order_id' => $orderId,
                'transaction_status' => $transactionStatus,
            ]);
        }

        if (in_array($transactionStatus, ['refund', 'partial_refund', 'chargeback', 'partial_chargeback'], true)) {
            $metadata = is_array($attempt->metadata) ? $attempt->metadata : [];
            $metadata['reversal'] = [
                'provider' => 'midtrans',
                'transaction_status' => $transactionStatus,
                'transaction_id' => $transactionId,
                'reversed_at' => now()->toISOString(),
                'payload' => $payload,
            ];

            $attempt->update([
                'metadata' => $metadata,
                'failure_code' => 'midtrans_'.$transactionStatus,
                'failure_message' => (string) ($payload['status_message'] ?? 'Transaksi Midtrans mengalami pembalikan pembayaran (reversal/refund).'),
            ]);

            if ($attempt->invoice !== null) {
                try {
                    $referralService = app(ReferralCommissionService::class);
                    $referralService->reverseCommission(
                        $attempt->invoice,
                        "Midtrans webhook reversal: {$transactionStatus}",
                        null,
                        $request->ip()
                    );
                } catch (\Throwable) {
                    // Safe idempotency if no referral commission or already reversed
                }
            }

            $this->auditService->record(
                action: 'webhook.reversal',
                status: 'processed',
                merchantCode: $attempt->gateway_merchant_code,
                metadata: [
                    'order_id' => $orderId,
                    'transaction_status' => $transactionStatus,
                    'transaction_id' => $transactionId,
                    'invoice_id' => $attempt->invoice_id,
                ]
            );

            return response()->json([
                'status' => 'OK',
                'message' => 'Pembalikan pembayaran (reversal/refund) berhasil diproses.',
                'order_id' => $orderId,
                'transaction_status' => $transactionStatus,
            ]);
        }

        return response()->json([
            'status' => 'OK',
            'message' => 'Webhook berhasil diterima.',
            'order_id' => $orderId,
            'transaction_status' => $transactionStatus,
        ]);
    }
}
