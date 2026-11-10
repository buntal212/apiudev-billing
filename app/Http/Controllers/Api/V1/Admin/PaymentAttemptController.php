<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListPaymentAttemptsRequest;
use App\Http\Resources\PaymentAttemptResource;
use App\Models\CheckoutOutboxMessage;
use App\Models\MidtransMerchantCredential;
use App\Models\PaymentAttempt;
use App\Services\CheckoutService;
use App\Services\DanaGatewayClient;
use App\Services\DanaGatewayMerchantResolver;
use App\Services\MidtransSecretStore;
use App\Services\PaymentAttemptService;
use App\Services\PaymentReconciliationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PaymentAttemptController extends Controller
{
    public function index(ListPaymentAttemptsRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PaymentAttempt::class);

        $filters = $request->validated();
        $paymentAttempts = PaymentAttempt::query()
            ->with([
                'invoice.subscription',
                'checkoutOutbox',
                'latestReconciliationRun',
                'paymentAllocation',
                'settlementEvents',
            ])
            ->when($filters['application_id'] ?? null, function (Builder $query, int $applicationId): void {
                $query->whereHas('invoice.customer', function (Builder $query) use ($applicationId): void {
                    $query->where('application_id', $applicationId);
                });
            })
            ->when($filters['application_customer_id'] ?? null, function (Builder $query, int $customerId): void {
                $query->whereHas('invoice', function (Builder $query) use ($customerId): void {
                    $query->where('application_customer_id', $customerId);
                });
            })
            ->when($filters['invoice_id'] ?? null, function (Builder $query, int $invoiceId): void {
                $query->where('invoice_id', $invoiceId);
            })
            ->when($filters['provider'] ?? null, function (Builder $query, string $provider): void {
                $query->where('provider', $provider);
            })
            ->when($filters['status'] ?? null, function (Builder $query, string $status): void {
                $query->where('status', $status);
            })
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return PaymentAttemptResource::collection($paymentAttempts);
    }

    public function show(PaymentAttempt $paymentAttempt): PaymentAttemptResource
    {
        $this->authorize('view', $paymentAttempt);

        return new PaymentAttemptResource($paymentAttempt->load([
            'invoice.subscription',
            'checkoutOutbox',
            'latestReconciliationRun',
            'paymentAllocation',
            'settlementEvents',
        ]));
    }

    public function reconcile(
        PaymentAttempt $paymentAttempt,
        PaymentReconciliationService $reconciliationService,
    ): PaymentAttemptResource {
        $this->authorize('update', $paymentAttempt);
        $reconciliationService->reconcile($paymentAttempt, true);

        return new PaymentAttemptResource($paymentAttempt->fresh()->load([
            'invoice.subscription',
            'checkoutOutbox',
            'latestReconciliationRun',
            'paymentAllocation',
            'settlementEvents',
        ]));
    }

    public function retryCheckout(
        PaymentAttempt $paymentAttempt,
        CheckoutService $checkoutService,
    ): PaymentAttemptResource {
        $this->authorize('update', $paymentAttempt);
        $this->authorize('update', $paymentAttempt->invoice);

        $attempt = $checkoutService->retryCheckout($paymentAttempt);

        return new PaymentAttemptResource($attempt->load([
            'invoice.subscription',
            'checkoutOutbox',
            'latestReconciliationRun',
            'paymentAllocation',
            'settlementEvents',
        ]));
    }

    public function cancel(
        Request $request,
        PaymentAttempt $paymentAttempt,
        DanaGatewayClient $gatewayClient,
        DanaGatewayMerchantResolver $merchantResolver,
        PaymentAttemptService $paymentAttemptService,
    ): PaymentAttemptResource {
        $this->authorize('update', $paymentAttempt);
        $this->authorize('update', $paymentAttempt->invoice);

        if (! in_array($paymentAttempt->status, [PaymentAttempt::StatusInitiated, PaymentAttempt::StatusPending], true)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, "Percobaan pembayaran dengan status '{$paymentAttempt->status}' tidak dapat dibatalkan.");
        }

        $reason = (string) ($request->input('reason') ?? 'Dibatalkan oleh admin melalui invoice.');

        if ($paymentAttempt->provider === 'dana' && ! empty($paymentAttempt->gateway_payment_id)) {
            try {
                $merchant = $merchantResolver->resolve(
                    (string) $paymentAttempt->gateway_merchant_code,
                    (string) $paymentAttempt->gateway_environment,
                );
                $gatewayClient->cancelPayment($merchant, (string) $paymentAttempt->gateway_payment_id, $reason);
            } catch (\Throwable $e) {
                Log::warning('Gagal membatalkan transaksi DANA di gateway.', ['attempt_id' => $paymentAttempt->id, 'error' => $e->getMessage()]);
            }
        }

        $cancelledAttempt = $paymentAttemptService->transitionStatus(
            $paymentAttempt,
            PaymentAttempt::StatusCancelled,
            failureCode: 'user_cancelled',
            failureMessage: $reason,
        );

        $outbox = CheckoutOutboxMessage::query()
            ->where('payment_attempt_id', $paymentAttempt->id)
            ->first();

        if ($outbox !== null && $outbox->status !== CheckoutOutboxMessage::StatusCompleted) {
            $outbox->update([
                'status' => CheckoutOutboxMessage::StatusFailed,
                'error_code' => 'attempt_cancelled',
            ]);
        }

        return new PaymentAttemptResource($cancelledAttempt->fresh()->load([
            'invoice.subscription',
            'checkoutOutbox',
            'latestReconciliationRun',
            'paymentAllocation',
            'settlementEvents',
        ]));
    }

    public function inquiry(
        PaymentAttempt $paymentAttempt,
        PaymentReconciliationService $reconciliationService,
    ): PaymentAttemptResource {
        $this->authorize('update', $paymentAttempt);
        $reconciliationService->reconcile($paymentAttempt, true);

        return new PaymentAttemptResource($paymentAttempt->fresh()->load([
            'invoice.subscription',
            'checkoutOutbox',
            'latestReconciliationRun',
            'paymentAllocation',
            'settlementEvents',
        ]));
    }

    public function qrImage(
        PaymentAttempt $paymentAttempt,
        MidtransSecretStore $secretStore,
    ): Response {
        $this->authorize('view', $paymentAttempt);

        if ($paymentAttempt->status === PaymentAttempt::StatusExpired
            || ($paymentAttempt->checkout_expires_at !== null && $paymentAttempt->checkout_expires_at->isPast())
        ) {
            return response()->json([
                'message' => 'Kode QRIS telah kedaluwarsa.',
                'error' => 'qris_expired',
            ], Response::HTTP_GONE);
        }

        if ($paymentAttempt->provider !== 'midtrans') {
            if ($paymentAttempt->provider === 'dana') {
                $metadata = is_array($paymentAttempt->metadata) ? $paymentAttempt->metadata : [];
                $instruction = is_array($metadata['payment_instruction'] ?? null) ? $metadata['payment_instruction'] : [];
                $qrContent = $metadata['qr_content'] ?? $instruction['qr_content'] ?? null;

                return response()->json([
                    'message' => 'Transaksi DANA QRIS menggunakan payload EMVCo MPM (qr_content) dan dirender di frontend client.',
                    'provider' => 'dana',
                    'payment_method' => 'qris',
                    'qr_content' => $qrContent,
                ], Response::HTTP_OK);
            }

            abort(Response::HTTP_NOT_FOUND, 'Payment attempt bukan transaksi Midtrans.');
        }

        $metadata = is_array($paymentAttempt->metadata) ? $paymentAttempt->metadata : [];
        $instruction = is_array($metadata['payment_instruction'] ?? null) ? $metadata['payment_instruction'] : [];
        $qrUrl = $metadata['qr_url']
            ?? $instruction['qr_url']
            ?? null;

        $txId = $paymentAttempt->gateway_payment_id ?? $metadata['transaction_id'] ?? null;
        if (empty($qrUrl) && ! empty($txId)) {
            $env = $paymentAttempt->gateway_environment === 'production' ? '' : 'sandbox.';
            $qrUrl = "https://api.{$env}midtrans.com/v2/qris/{$txId}/qr-code";
        }

        if (empty($qrUrl)) {
            abort(Response::HTTP_NOT_FOUND, 'URL gambar QRIS tidak ditemukan.');
        }

        $credential = MidtransMerchantCredential::query()
            ->where('merchant_code', $paymentAttempt->gateway_merchant_code)
            ->first();

        $serverKey = $credential ? $credential->getServerKey($secretStore) : null;

        $httpClient = Http::timeout(10)->connectTimeout(3);
        if (! empty($serverKey)) {
            $httpClient = $httpClient->withBasicAuth(trim($serverKey), '');
        }

        try {
            $res = $httpClient->get($qrUrl);

            if (! $res->successful()) {
                return response()->json([
                    'message' => 'Gagal mengambil gambar QRIS dari Midtrans Sandbox.',
                    'status' => $res->status(),
                ], Response::HTTP_BAD_GATEWAY);
            }

            $contentType = $res->header('Content-Type') ?: 'image/png';

            return response($res->body(), Response::HTTP_OK, [
                'Content-Type' => $contentType,
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Gagal terhubung ke server Midtrans untuk gambar QRIS.',
                'error' => 'gateway_connection_failed',
            ], Response::HTTP_BAD_GATEWAY);
        }
    }
}
