<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListApplicationsRequest;
use App\Http\Requests\StoreApplicationRequest;
use App\Http\Requests\UpdateApplicationRequest;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
}
