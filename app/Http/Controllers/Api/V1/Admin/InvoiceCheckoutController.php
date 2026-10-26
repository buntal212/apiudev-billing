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

        $paymentMethod = (string) ($request->validated('payment_method') ?? 'dana_wallet');
        $attempt = $checkoutService->startCheckout($invoice, $request->validated('idempotency_key'), $paymentMethod);
        $outbox = CheckoutOutboxMessage::query()
            ->where('payment_attempt_id', $attempt->getKey())
            ->first();

        if ($outbox?->status === CheckoutOutboxMessage::StatusFailed) {
            abort(Response::HTTP_BAD_GATEWAY, 'Checkout gateway tidak dapat diproses.');
        }

        $resource = new InvoiceCheckoutResource($attempt, $outbox);
        $isReady = $attempt->checkout_url !== null
            || ! empty($attempt->metadata['qr_content'] ?? null)
            || ! empty($attempt->metadata['payment_code'] ?? null);

        return $resource
            ->response()
            ->setStatusCode($isReady ? Response::HTTP_OK : Response::HTTP_ACCEPTED);
    }
}
