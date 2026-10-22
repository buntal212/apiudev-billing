<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListInvoicesRequest;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Services\InvoiceSettlementService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    public function index(ListInvoicesRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Invoice::class);

        $filters = $request->validated();
        $invoices = Invoice::query()
            ->with(['customer', 'plan', 'subscription'])
            ->when($filters['application_id'] ?? null, function (Builder $query, int $applicationId): void {
                $query->whereHas('customer', function (Builder $query) use ($applicationId): void {
                    $query->where('application_id', $applicationId);
                });
            })
            ->when($filters['application_customer_id'] ?? null, function (Builder $query, int $customerId): void {
                $query->where('application_customer_id', $customerId);
            })
            ->when($filters['subscription_plan_id'] ?? null, function (Builder $query, int $planId): void {
                $query->where('subscription_plan_id', $planId);
            })
            ->when($filters['subscription_id'] ?? null, function (Builder $query, int $subscriptionId): void {
                $query->where('subscription_id', $subscriptionId);
            })
            ->when($filters['status'] ?? null, function (Builder $query, string $status): void {
                $query->where('status', $status);
            })
            ->when($filters['due_from'] ?? null, function (Builder $query, string $dueFrom): void {
                $query->where('due_at', '>=', $dueFrom);
            })
            ->when($filters['due_to'] ?? null, function (Builder $query, string $dueTo): void {
                $query->where('due_at', '<=', $dueTo);
            })
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return InvoiceResource::collection($invoices);
    }

    public function store(StoreInvoiceRequest $request, InvoiceService $invoiceService): JsonResponse
    {
        $this->authorize('create', Invoice::class);

        $data = $request->validated();
        $invoice = $invoiceService->createInvoice(
            customerId: $data['application_customer_id'],
            planId: $data['subscription_plan_id'],
            subscriptionId: $data['subscription_id'] ?? null,
            dueAt: CarbonImmutable::parse($data['due_at']),
            idempotencyKey: $data['idempotency_key'],
        );

        return (new InvoiceResource($invoice->load(['customer', 'plan', 'subscription'])))
            ->response()
            ->setStatusCode(
                $invoice->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK,
            );
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        $this->authorize('view', $invoice);

        return new InvoiceResource($invoice->load([
            'customer',
            'plan',
            'subscription',
            'paymentAttempts.latestReconciliationRun',
            'paymentAttempts.checkoutOutbox',
            'paymentAttempts.paymentAllocation',
            'paymentAttempts.settlementEvents',
        ]));
    }

    public function recoverSubscription(
        Invoice $invoice,
        InvoiceSettlementService $settlementService,
    ): InvoiceResource {
        $this->authorize('update', $invoice);

        $recoveredInvoice = $settlementService->recoverSubscription($invoice);

        return new InvoiceResource($recoveredInvoice->load([
            'customer',
            'plan',
            'subscription.latestPeriodEvent',
        ]));
    }
}
