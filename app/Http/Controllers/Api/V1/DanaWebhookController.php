<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymentAttempt;
use App\Services\DanaGatewayClient;
use App\Services\DanaGatewayMerchantResolver;
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
        private readonly DanaGatewayClient $gatewayClient,
        private readonly DanaGatewayMerchantResolver $merchantResolver,
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

        // 2. Autentikasi Permintaan Webhook
        if (! $this->isAuthorized($request, $attempt)) {
            Log::warning('Webhook DANA ditolak: permintaan tidak terautentikasi.', [
                'merchant_reference' => $merchantReference,
                'gateway_payment_id' => $gatewayPaymentId,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Akses ditolak. Webhook DANA memerlukan autentikasi yang valid.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // 3. Status Idempoten: Jika sudah sukses sebelumnya, langsung kembalikan OK
        if ($attempt->status === PaymentAttempt::StatusSucceeded) {
            return response()->json([
                'status' => 'OK',
                'message' => 'Payment attempt sudah diselesaikan sebelumnya (idempoten).',
                'merchant_reference' => $attempt->merchant_reference,
            ]);
        }

        // 4. Identify transaction status
        $status = strtolower((string) ($payload['status'] ?? ''));
        $snapStatus = (string) ($payload['latestTransactionStatus'] ?? '');

        $isPaid = $status === 'paid' || $snapStatus === '00';
        $isCancelled = in_array($status, ['cancelled', 'cancel'], true) || $snapStatus === '02';
        $isExpired = in_array($status, ['expired', 'expire'], true) || $snapStatus === '05';

        if ($isPaid) {
            // Verifikasi status riil ke DANA Gateway sebelum melakukan settlement
            try {
                $merchant = $this->merchantResolver->resolve(
                    (string) $attempt->gateway_merchant_code,
                    (string) $attempt->gateway_environment,
                );

                $targetPaymentId = (string) ($gatewayPaymentId ?: $attempt->gateway_payment_id);
                $gatewayPayment = ! empty($targetPaymentId)
                    ? $this->gatewayClient->findPayment($merchant, $targetPaymentId)
                    : $this->gatewayClient->findPaymentByMerchantOrderId($merchant, (string) $attempt->merchant_reference);
            } catch (\Throwable $e) {
                Log::error('Gagal memverifikasi status pembayaran ke gateway DANA.', [
                    'payment_attempt_id' => $attempt->id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Gagal memverifikasi status pembayaran ke gateway DANA.',
                ], Response::HTTP_BAD_GATEWAY);
            }

            if (! is_array($gatewayPayment)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Transaksi tidak ditemukan pada gateway DANA.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $gatewayStatus = strtolower((string) ($gatewayPayment['status'] ?? ''));
            if ($gatewayStatus !== 'paid') {
                Log::warning('Webhook DANA ditolak: status gateway belum terverifikasi lunas.', [
                    'payment_attempt_id' => $attempt->id,
                    'gateway_status' => $gatewayStatus,
                    'payload_status' => $status,
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Status pembayaran belum terverifikasi lunas oleh gateway DANA.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Validasi nominal dan mata uang hasil gateway
            $expectedAmountMinor = (int) round((float) $attempt->getRawOriginal('amount'));
            $gatewayAmountMinor = (int) ($gatewayPayment['amount_minor'] ?? 0);
            if ($gatewayAmountMinor !== $expectedAmountMinor || ($gatewayPayment['currency'] ?? 'IDR') !== (string) $attempt->currency) {
                Log::error('Nominal atau mata uang gateway DANA tidak sesuai dengan payment attempt.', [
                    'expected_amount' => $expectedAmountMinor,
                    'gateway_amount' => $gatewayAmountMinor,
                    'expected_currency' => $attempt->currency,
                    'gateway_currency' => $gatewayPayment['currency'] ?? null,
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Data nominal atau mata uang transaksi gateway tidak sesuai.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $paidAtStr = $gatewayPayment['paid_at'] ?? $payload['paid_at'] ?? $payload['finishedTime'] ?? $payload['created_at'] ?? null;
            $paidAt = is_string($paidAtStr) ? CarbonImmutable::parse($paidAtStr) : now()->toImmutable();

            $updatedAttempt = $attempt->status === PaymentAttempt::StatusInitiated
                ? $this->paymentAttemptService->transitionStatus($attempt, PaymentAttempt::StatusPending)
                : $attempt;

            $resolvedGatewayPaymentId = (string) ($gatewayPayment['id'] ?? $gatewayPaymentId ?? $updatedAttempt->gateway_payment_id);
            $providerOrderRef = (string) ($gatewayPayment['provider_order_id'] ?? $gatewayPaymentId ?? $updatedAttempt->gateway_payment_id);

            $updatedAttempt = $this->paymentAttemptService->transitionStatus(
                $updatedAttempt,
                PaymentAttempt::StatusSucceeded,
                providerReference: $providerOrderRef,
                completedAt: $paidAt,
            );

            // Perbaiki konsistensi metadata gateway_verification untuk PaymentResultController
            $metadata = is_array($updatedAttempt->metadata) ? $updatedAttempt->metadata : [];
            $metadata['gateway_verification'] = [
                'provider' => 'dana',
                'gateway_payment_id' => $resolvedGatewayPaymentId,
                'verified_at' => now()->toISOString(),
                'gateway_paid_at' => $paidAt->toISOString(),
            ];
            $updatedAttempt->update(['metadata' => $metadata]);

            $this->invoiceSettlementService->settle(
                $updatedAttempt,
                VerifiedGatewayPayment::paid(
                    provider: 'dana',
                    gatewayPaymentId: $resolvedGatewayPaymentId,
                    merchantOrderId: (string) $attempt->merchant_reference,
                    amountMinor: $expectedAmountMinor,
                    currency: (string) $attempt->currency,
                    merchantCode: (string) $attempt->gateway_merchant_code,
                    environment: (string) $attempt->gateway_environment,
                    paidAt: $paidAt,
                ),
            );

            return response()->json([
                'status' => 'OK',
                'message' => 'Webhook DANA berhasil diverifikasi gateway dan invoice telah lunas.',
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

    private function isAuthorized(Request $request, ?PaymentAttempt $attempt): bool
    {
        $candidateTokens = array_filter([
            $request->bearerToken(),
            $request->header('X-Webhook-Secret'),
            $request->header('X-Service-Token'),
            $request->header('X-Callback-Token'),
            $request->input('webhook_secret'),
            $request->input('service_token'),
        ], fn ($t) => is_string($t) && trim($t) !== '');

        if (empty($candidateTokens)) {
            return false;
        }

        $expectedTokens = array_filter([
            config('services.dana_gateway.webhook_secret'),
            config('services.dana_merchant_provisioning.service_token'),
            config('services.dana_gateway.service_token'),
        ], fn ($t) => is_string($t) && trim($t) !== '');

        if ($attempt !== null && ! empty($attempt->gateway_merchant_code)) {
            $env = (string) ($attempt->gateway_environment ?: config('services.dana_gateway.environment', 'sandbox'));
            $merchantKey = config("services.dana_gateway.merchant_api_keys.{$env}.{$attempt->gateway_merchant_code}");
            if (is_string($merchantKey) && trim($merchantKey) !== '') {
                $expectedTokens[] = trim($merchantKey);
            }

            try {
                $merchant = $this->merchantResolver->resolve(
                    (string) $attempt->gateway_merchant_code,
                    $env,
                );
                if (! empty($merchant->apiKey)) {
                    $expectedTokens[] = trim($merchant->apiKey);
                }
            } catch (\Throwable) {
                // Abaikan kesalahan resolver saat membaca token
            }
        }

        $expectedTokens = array_unique($expectedTokens);
        if (empty($expectedTokens)) {
            return false;
        }

        foreach ($candidateTokens as $given) {
            foreach ($expectedTokens as $expected) {
                if (hash_equals($expected, $given)) {
                    return true;
                }
            }
        }

        return false;
    }
}
