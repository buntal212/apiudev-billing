<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListApplicationsRequest;
use App\Http\Requests\StoreApplicationRequest;
use App\Http\Requests\UpdateApplicationRequest;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ApplicationController extends Controller
{
    public function index(ListApplicationsRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Application::class);

        $filters = $request->validated();
        $applications = Application::query()
            ->withCount([
                'customers',
                'customers as integration_customers_count' => function (Builder $query): void {
                    $query->where('source', 'integration');
                },
            ])
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('contact_email', 'like', "%{$search}%");
                });
            })
            ->when(array_key_exists('is_active', $filters), function (Builder $query) use ($request): void {
                $query->where('is_active', $request->boolean('is_active'));
            })
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return ApplicationResource::collection($applications);
    }

    public function store(StoreApplicationRequest $request): JsonResponse
    {
        $this->authorize('create', Application::class);

        $application = Application::create($request->validated());

        return (new ApplicationResource($application))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Application $application): ApplicationResource
    {
        $this->authorize('view', $application);

        return new ApplicationResource(
            $application->loadCount([
                'customers',
                'integrationTokens',
                'customers as integration_customers_count' => function (Builder $query): void {
                    $query->where('source', 'integration');
                },
            ])
                ->loadMax('customers', 'last_synced_at'),
        );
    }

    public function update(UpdateApplicationRequest $request, Application $application): ApplicationResource
    {
        $this->authorize('update', $application);

        $application->update($request->validated());

        return new ApplicationResource($application->refresh());
    }

    public function destroy(Application $application): JsonResponse
    {
        $this->authorize('delete', $application);

        $conflictMessage = 'Aplikasi tidak dapat dihapus karena memiliki data terkait. Silakan nonaktifkan aplikasi.';

        try {
            DB::transaction(function () use ($application, $conflictMessage): void {
                $lockedApplication = Application::query()
                    ->whereKey($application->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($lockedApplication === null) {
                    abort(Response::HTTP_NOT_FOUND);
                }

                if ($this->hasRelatedData($lockedApplication)) {
                    abort(Response::HTTP_CONFLICT, $conflictMessage);
                }

                $lockedApplication->delete();
            });
        } catch (QueryException $exception) {
            if ($this->isForeignKeyConstraintViolation($exception)) {
                abort(Response::HTTP_CONFLICT, $conflictMessage);
            }

            throw $exception;
        }

        return response()->json([
            'message' => 'Aplikasi berhasil dihapus.',
        ]);
    }

    private function hasRelatedData(Application $application): bool
    {
        if (
            $application->customers()->exists()
            || $application->subscriptionPlans()->exists()
            || $application->paymentGateways()->exists()
            || $application->integrationTokens()->exists()
            || $application->integrationAudits()->exists()
            || $application->customerReferrals()->exists()
            || $application->danaPaymentFeeSettings()->exists()
        ) {
            return true;
        }

        $applicationId = $application->getKey();

        return Subscription::query()
            ->where(function (Builder $query) use ($applicationId): void {
                $query->whereHas('customer', fn (Builder $customerQuery) => $customerQuery->where('application_id', $applicationId))
                    ->orWhereHas('plan', fn (Builder $planQuery) => $planQuery->where('application_id', $applicationId));
            })
            ->exists()
            || Invoice::query()
                ->where(function (Builder $query) use ($applicationId): void {
                    $query->whereHas('customer', fn (Builder $customerQuery) => $customerQuery->where('application_id', $applicationId))
                        ->orWhereHas('plan', fn (Builder $planQuery) => $planQuery->where('application_id', $applicationId));
                })
                ->exists()
            || PaymentAttempt::query()
                ->whereHas('invoice', function (Builder $invoiceQuery) use ($applicationId): void {
                    $invoiceQuery->whereHas('customer', fn (Builder $customerQuery) => $customerQuery->where('application_id', $applicationId))
                        ->orWhereHas('plan', fn (Builder $planQuery) => $planQuery->where('application_id', $applicationId));
                })
                ->exists();
    }

    private function isForeignKeyConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return $sqlState === '23000' || $sqlState === '23503' || $driverCode === 1451 || $driverCode === 19;
    }
}
