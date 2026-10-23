<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListBillingGatewayTransactionsRequest;
use App\Http\Resources\BillingGatewayTransactionResource;
use App\Models\PaymentAttempt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BillingGatewayTransactionController extends Controller
{
    public function index(ListBillingGatewayTransactionsRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PaymentAttempt::class);

        $filters = $request->validated();
        $transactions = $this->query($filters)
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return BillingGatewayTransactionResource::collection($transactions);
    }

    public function show(PaymentAttempt $paymentAttempt): BillingGatewayTransactionResource
    {
        $this->authorize('view', $paymentAttempt);

        abort_unless(
            strtolower($paymentAttempt->provider) === 'dana'
            && $paymentAttempt->gateway_environment === 'sandbox',
            404,
        );

        return new BillingGatewayTransactionResource($paymentAttempt->load($this->relations()));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<PaymentAttempt>
     */
    private function query(array $filters): Builder
    {
        return PaymentAttempt::query()
            ->with($this->relations())
            ->whereRaw('LOWER(provider) = ?', ['dana'])
            ->where('gateway_environment', 'sandbox')
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
            ->when($filters['customer'] ?? null, function (Builder $query, string $customer): void {
                $query->whereHas('invoice.customer', function (Builder $query) use ($customer): void {
                    $query->where('name', 'like', '%'.$customer.'%');
                });
            })
            ->when($filters['invoice_number'] ?? null, function (Builder $query, string $invoiceNumber): void {
                $query->whereHas('invoice', function (Builder $query) use ($invoiceNumber): void {
                    $query->where('invoice_number', 'like', '%'.$invoiceNumber.'%');
                });
            })
            ->when($filters['status'] ?? null, function (Builder $query, string $status): void {
                $query->where(function (Builder $query) use ($status): void {
                    $query->where('status', $status)
                        ->orWhereHas('latestReconciliationRun', function (Builder $query) use ($status): void {
                            $query->where('gateway_status', $status);
                        });
                });
            })
            ->when($filters['date_from'] ?? null, function (Builder $query, string $dateFrom): void {
                $query->where('requested_at', '>=', $dateFrom.' 00:00:00');
            })
            ->when($filters['date_to'] ?? null, function (Builder $query, string $dateTo): void {
                $query->where('requested_at', '<=', $dateTo.' 23:59:59');
            });
    }

    /** @return list<string> */
    private function relations(): array
    {
        return [
            'invoice.customer.application',
            'invoice.subscription.latestPeriodEvent',
            'latestReconciliationRun',
            'paymentAllocation',
            'settlementEvents',
        ];
    }
}
