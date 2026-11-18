<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StartInvoiceCheckoutRequest;
use App\Http\Resources\InvoiceCheckoutResource;
use App\Models\CheckoutOutboxMessage;
use App\Models\Invoice;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class InvoiceCheckoutController extends Controller
{
    public function store(StartInvoiceCheckoutRequest $request, Invoice $invoice, CheckoutService $checkoutService): JsonResponse
    {
        $this->authorize('update', $invoice);

        $paymentMethod = (string) ($request->validated('payment_method') ?? 'qris');
        $provider = $request->validated('provider');
        $bank = $request->validated('bank');
        $attempt = $checkoutService->startCheckout($invoice, $request->validated('idempotency_key'), $paymentMethod, $provider, $bank);
        $outbox = CheckoutOutboxMessage::query()
            ->where('payment_attempt_id', $attempt->getKey())
            ->first();

        $attemptMetadata = is_array($attempt->metadata) ? $attempt->metadata : [];
        $isUncertain = ($attempt->failure_code === 'gateway_uncertain_status')
            || ($attemptMetadata['uncertain'] ?? false)
            || ($attemptMetadata['is_uncertain'] ?? false)
            || ($outbox?->status === CheckoutOutboxMessage::StatusFailed && $outbox?->error_code === 'transaction_already_registered');

        if ($isUncertain) {
            abort(Response::HTTP_CONFLICT, $attempt->failure_message ?: 'Transaksi telah terdaftar di DANA (status 01) tetapi instruksi pembayaran tidak tersedia. Pembuatan order baru diblokir demi keamanan.');
        }

        if ($outbox?->status === CheckoutOutboxMessage::StatusFailed || $attempt->status === \App\Models\PaymentAttempt::StatusFailed) {
            abort(Response::HTTP_BAD_GATEWAY, $attempt->failure_message ?: 'Checkout gateway tidak dapat diproses.');
        }

        $resource = new InvoiceCheckoutResource($attempt, $outbox);
        $isReady = $attempt->checkout_url !== null
            || ! empty($attempt->metadata['qr_content'] ?? null)
            || ! empty($attempt->metadata['qr_url'] ?? null)
            || ! empty($attempt->metadata['payment_code'] ?? null);

        return $resource
            ->response()
            ->setStatusCode($isReady ? Response::HTTP_OK : Response::HTTP_ACCEPTED);
    }
}
