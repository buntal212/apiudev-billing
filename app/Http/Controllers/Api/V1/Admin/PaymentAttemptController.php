<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListPaymentAttemptsRequest;
use App\Http\Resources\PaymentAttemptResource;
use App\Models\PaymentAttempt;
use App\Services\CheckoutService;
use App\Services\PaymentReconciliationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
        $reconciliationService->reconcile($paymentAttempt);

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
}
