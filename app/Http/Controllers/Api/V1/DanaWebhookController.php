<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymentAttempt;
use App\Services\InvoiceSettlementService;
use App\Services\PaymentAttemptService;
use App\Services\VerifiedGatewayPayment;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class DanaWebhookController extends Controller
{
    public function __construct(
        private readonly InvoiceSettlementService $invoiceSettlementService,
        private readonly PaymentAttemptService $paymentAttemptService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->all();

        // 1. Identify transaction reference
        $merchantReference = $payload['merchant_order_id']
            ?? $payload['originalPartnerReferenceNo']
            ?? $payload['partnerReferenceNo']
            ?? null;

        $gatewayPaymentId = $payload['id']
            ?? $payload['payment_id']
            ?? $payload['originalReferenceNo']
            ?? null;

        if (empty($merchantReference) && empty($gatewayPaymentId)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Parameter referensi transaksi tidak ditemukan pada webhook DANA.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $attempt = PaymentAttempt::query()
            ->where('provider', 'dana')
            ->where(function ($query) use ($merchantReference, $gatewayPaymentId): void {
                if (! empty($merchantReference)) {
                    $query->where('merchant_reference', $merchantReference);
                }
                if (! empty($gatewayPaymentId)) {
                    $query->orWhere('gateway_payment_id', $gatewayPaymentId);
                }
            })
            ->latest('id')
            ->first();

        if ($attempt === null) {
            Log::warning('PaymentAttempt DANA tidak ditemukan untuk webhook.', ['payload' => $payload]);

            return response()->json([
                'status' => 'skipped',
                'message' => 'Payment attempt tidak ditemukan untuk referensi ini. Webhook dilewati.',
            ], Response::HTTP_OK);
        }

        // 2. Identify transaction status
        $status = strtolower((string) ($payload['status'] ?? ''));
        $snapStatus = (string) ($payload['latestTransactionStatus'] ?? '');

        $isPaid = $status === 'paid' || $snapStatus === '00';
        $isCancelled = in_array($status, ['cancelled', 'cancel'], true) || $snapStatus === '02';
        $isExpired = in_array($status, ['expired', 'expire'], true) || $snapStatus === '05';

        if ($isPaid) {
            if ($attempt->status === PaymentAttempt::StatusSucceeded) {
                return response()->json([
                    'status' => 'OK',
                    'message' => 'Payment attempt sudah diselesaikan sebelumnya (idempoten).',
                    'merchant_reference' => $attempt->merchant_reference,
                ]);
            }

            $paidAtStr = $payload['paid_at'] ?? $payload['finishedTime'] ?? $payload['created_at'] ?? null;
            $paidAt = is_string($paidAtStr) ? CarbonImmutable::parse($paidAtStr) : now()->toImmutable();

            $amountVal = $payload['amount_minor'] ?? $payload['amount']['value'] ?? $payload['amount'] ?? null;
            $amountMinor = is_numeric($amountVal) ? (int) $amountVal : (int) round((float) $attempt->getRawOriginal('amount'));

            $updatedAttempt = $attempt->status === PaymentAttempt::StatusInitiated
                ? $this->paymentAttemptService->transitionStatus($attempt, PaymentAttempt::StatusPending)
                : $attempt;

            $updatedAttempt = $this->paymentAttemptService->transitionStatus(
                $updatedAttempt,
                PaymentAttempt::StatusSucceeded,
                providerReference: (string) ($gatewayPaymentId ?: $updatedAttempt->gateway_payment_id),
                completedAt: $paidAt,
            );

            $this->invoiceSettlementService->settle(
                $updatedAttempt,
                VerifiedGatewayPayment::paid(
                    provider: 'dana',
                    gatewayPaymentId: (string) ($gatewayPaymentId ?: $updatedAttempt->gateway_payment_id),
                    merchantOrderId: (string) $attempt->merchant_reference,
                    amountMinor: $amountMinor,
                    currency: (string) $attempt->currency,
                    merchantCode: (string) $attempt->gateway_merchant_code,
                    environment: (string) $attempt->gateway_environment,
                    paidAt: $paidAt,
                ),
            );

            return response()->json([
                'status' => 'OK',
                'message' => 'Webhook DANA berhasil diproses dan invoice telah lunas.',
                'merchant_reference' => $attempt->merchant_reference,
            ]);
        }

        if ($isCancelled) {
            if (! in_array($attempt->status, [PaymentAttempt::StatusSucceeded, PaymentAttempt::StatusCancelled], true)) {
                $this->paymentAttemptService->transitionStatus(
                    $attempt,
                    PaymentAttempt::StatusCancelled,
                    providerReference: (string) ($gatewayPaymentId ?: $attempt->gateway_payment_id),
                    failureCode: 'dana_cancelled',
                    failureMessage: 'Transaksi dibatalkan pada gateway DANA.',
                );
            }

            return response()->json([
                'status' => 'OK',
                'message' => 'Transaksi DANA ditandai sebagai dibatalkan.',
            ]);
        }

        if ($isExpired) {
            if (! in_array($attempt->status, [PaymentAttempt::StatusSucceeded, PaymentAttempt::StatusExpired], true)) {
                $this->paymentAttemptService->transitionStatus(
                    $attempt,
                    PaymentAttempt::StatusExpired,
                    providerReference: (string) ($gatewayPaymentId ?: $attempt->gateway_payment_id),
                    failureCode: 'dana_expired',
                    failureMessage: 'Batas waktu transaksi DANA telah habis.',
                );
            }

            return response()->json([
                'status' => 'OK',
                'message' => 'Transaksi DANA ditandai sebagai kedaluwarsa.',
            ]);
        }

        return response()->json([
            'status' => 'OK',
            'message' => 'Status webhook DANA diterima.',
        ]);
    }
}
