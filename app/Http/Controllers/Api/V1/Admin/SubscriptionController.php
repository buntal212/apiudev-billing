<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListSubscriptionsRequest;
use App\Http\Resources\SubscriptionResource;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SubscriptionController extends Controller
{
    public function index(ListSubscriptionsRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Subscription::class);

        $filters = $request->validated();
        $subscriptions = Subscription::query()
            ->with(['customer', 'plan'])
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
            ->when($filters['status'] ?? null, function (Builder $query, string $status): void {
                $query->where('status', $status);
            })
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return SubscriptionResource::collection($subscriptions);
    }

    public function show(Subscription $subscription): SubscriptionResource
    {
        $this->authorize('view', $subscription);

        return new SubscriptionResource($subscription->load([
            'customer',
            'plan',
            'periodEvents',
            'invoices.paymentAttempts.latestReconciliationRun',
            'invoices.paymentAttempts.paymentAllocation',
            'invoices.paymentAttempts.settlementEvents',
        ]));
    }
}
