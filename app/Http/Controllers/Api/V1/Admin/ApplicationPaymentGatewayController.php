<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListApplicationPaymentGatewaysRequest;
use App\Http\Requests\ListDanaGatewayMerchantsRequest;
use App\Http\Requests\StoreApplicationPaymentGatewayRequest;
use App\Http\Requests\UpdateApplicationPaymentGatewayRequest;
use App\Http\Resources\ApplicationPaymentGatewayResource;
use App\Models\ApplicationPaymentGateway;
use App\Services\DanaGatewayMerchantCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class ApplicationPaymentGatewayController extends Controller
{
    public function index(ListApplicationPaymentGatewaysRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ApplicationPaymentGateway::class);

        $filters = $request->validated();
        $gateways = ApplicationPaymentGateway::query()
            ->with('application:id,code,name')
            ->when($filters['application_id'] ?? null, fn ($query, int $applicationId) => $query->where('application_id', $applicationId))
            ->when($filters['provider'] ?? null, fn ($query, string $provider) => $query->where('provider', $provider))
            ->when($filters['environment'] ?? null, fn ($query, string $environment) => $query->where('environment', $environment))
            ->when(array_key_exists('is_active', $filters), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return ApplicationPaymentGatewayResource::collection($gateways);
    }

    public function merchants(ListDanaGatewayMerchantsRequest $request, DanaGatewayMerchantCatalog $merchantCatalog): JsonResponse
    {
        $this->authorize('viewAny', ApplicationPaymentGateway::class);

        return response()->json([
            'data' => $merchantCatalog->availableFor($request->validated('environment')),
        ]);
    }

    public function store(StoreApplicationPaymentGatewayRequest $request, DanaGatewayMerchantCatalog $merchantCatalog): JsonResponse
    {
        $this->authorize('create', ApplicationPaymentGateway::class);

        $attributes = $request->validated();
        $merchantCatalog->ensureUsable($attributes['gateway_merchant_code'], $attributes['environment']);
        $gateway = ApplicationPaymentGateway::query()->create($attributes);

        return (new ApplicationPaymentGatewayResource($gateway->load('application:id,code,name')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(
        UpdateApplicationPaymentGatewayRequest $request,
        ApplicationPaymentGateway $paymentGateway,
        DanaGatewayMerchantCatalog $merchantCatalog,
    ): ApplicationPaymentGatewayResource {
        $this->authorize('update', $paymentGateway);

        $attributes = $request->validated();
        $merchantCode = $attributes['gateway_merchant_code'] ?? $paymentGateway->gateway_merchant_code;
        $environment = $attributes['environment'] ?? $paymentGateway->environment;
        $willBeActive = $attributes['is_active'] ?? $paymentGateway->is_active;

        if ($willBeActive || array_key_exists('gateway_merchant_code', $attributes) || array_key_exists('environment', $attributes)) {
            $merchantCatalog->ensureUsable($merchantCode, $environment);
        }

        $paymentGateway->update($attributes);

        return new ApplicationPaymentGatewayResource($paymentGateway->fresh()->load('application:id,code,name'));
    }
}
