<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListSubscriptionPlansRequest;
use App\Http\Requests\StoreSubscriptionPlanRequest;
use App\Http\Requests\UpdateSubscriptionPlanRequest;
use App\Http\Resources\SubscriptionPlanResource;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class SubscriptionPlanController extends Controller
{
    public function index(ListSubscriptionPlansRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SubscriptionPlan::class);

        $filters = $request->validated();
        $plans = SubscriptionPlan::query()
            ->when($filters['application_id'] ?? null, function (Builder $query, int $applicationId): void {
                $query->where('application_id', $applicationId);
            })
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                });
            })
            ->when(array_key_exists('is_active', $filters), function (Builder $query) use ($request): void {
                $query->where('is_active', $request->boolean('is_active'));
            })
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return SubscriptionPlanResource::collection($plans);
    }

    public function store(StoreSubscriptionPlanRequest $request): JsonResponse
    {
        $this->authorize('create', SubscriptionPlan::class);

        $plan = SubscriptionPlan::create($this->withLegacyDurationMonths($request->validated()));

        return (new SubscriptionPlanResource($plan))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(SubscriptionPlan $subscriptionPlan): SubscriptionPlanResource
    {
        $this->authorize('view', $subscriptionPlan);

        return new SubscriptionPlanResource($subscriptionPlan);
    }

    public function update(UpdateSubscriptionPlanRequest $request, SubscriptionPlan $subscriptionPlan): SubscriptionPlanResource
    {
        $this->authorize('update', $subscriptionPlan);

        $subscriptionPlan->update($this->withLegacyDurationMonths($request->validated()));

        return new SubscriptionPlanResource($subscriptionPlan->refresh());
    }

    public function destroy(SubscriptionPlan $subscriptionPlan): JsonResponse
    {
        $this->authorize('delete', $subscriptionPlan);

        if ($subscriptionPlan->subscriptions()->exists()) {
            throw ValidationException::withMessages([
                'subscription_plan' => ['Paket langganan yang memiliki riwayat langganan tidak dapat dihapus.'],
            ]);
        }

        $subscriptionPlan->delete();

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * Kolom duration_months dipertahankan untuk kompatibilitas data lama.
     * Semua nilai baru tervalidasi sebagai kelipatan 30 hari.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withLegacyDurationMonths(array $data): array
    {
        if (isset($data['duration_days'])) {
            $data['duration_months'] = intdiv($data['duration_days'], 30);
        }

        return $data;
    }
}
