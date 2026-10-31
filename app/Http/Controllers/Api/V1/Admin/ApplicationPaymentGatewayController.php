<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListApplicationPaymentGatewaysRequest;
use App\Http\Requests\ListDanaGatewayMerchantsRequest;
use App\Http\Requests\StoreApplicationPaymentGatewayRequest;
use App\Http\Requests\UpdateApplicationPaymentGatewayRequest;
use App\Http\Resources\ApplicationPaymentGatewayResource;
use App\Models\ApplicationPaymentGateway;
use App\Models\DokuMerchantCredential;
use App\Services\DanaGatewayMerchantCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
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

        $provider = (string) $request->input('provider', 'dana');
        if ($provider === 'doku') {
            $existing = DokuMerchantCredential::query()
                ->where('environment', 'sandbox')
                ->get();

            $codes = array_unique(array_merge(['udev-central', 'larissama'], $existing->pluck('merchant_code')->all()));
            $result = [];
            foreach ($codes as $code) {
                $m = $existing->firstWhere('merchant_code', $code);
                $isConfigured = $m !== null && ! empty($m->encrypted_private_key);
                $result[] = [
                    'code' => $code,
                    'name' => $m?->merchant_name ?? ($code === 'udev-central' ? 'UDEV Central Billing' : $code),
                    'is_active' => $m?->is_active ?? true,
                    'readiness' => [
                        'merchant_configured' => $isConfigured,
                        'merchant_active' => $m?->is_active ?? true,
                        'credential_configured' => $isConfigured,
                        'base_url_configured' => true,
                        'ready' => ($m?->is_active ?? true) && $isConfigured,
                    ],
                ];
            }

            return response()->json(['data' => $result]);
        }

        return response()->json([
            'data' => $merchantCatalog->availableFor($request->validated('environment')),
        ]);
    }

    public function store(StoreApplicationPaymentGatewayRequest $request, DanaGatewayMerchantCatalog $merchantCatalog): JsonResponse
    {
        $this->authorize('create', ApplicationPaymentGateway::class);

        $attributes = $request->validated();
        if ($attributes['provider'] === 'dana') {
            $merchantCatalog->ensureUsable($attributes['gateway_merchant_code'], $attributes['environment']);
        } else {
            if ($attributes['environment'] !== 'sandbox') {
                throw ValidationException::withMessages([
                    'environment' => ['DOKU Gateway hanya mendukung environment sandbox.'],
                ]);
            }

            $exists = DokuMerchantCredential::query()
                ->where('merchant_code', $attributes['gateway_merchant_code'])
                ->where('environment', 'sandbox')
                ->exists();

            if (! $exists) {
                throw ValidationException::withMessages([
                    'gateway_merchant_code' => ['Merchant DOKU tidak ditemukan pada sandbox.'],
                ]);
            }
        }
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

        if ($paymentGateway->provider === 'dana') {
            if ($willBeActive || array_key_exists('gateway_merchant_code', $attributes) || array_key_exists('environment', $attributes)) {
                $merchantCatalog->ensureUsable($merchantCode, $environment);
            }
        } else {
            if ($environment !== 'sandbox') {
                throw ValidationException::withMessages([
                    'environment' => ['DOKU Gateway hanya mendukung environment sandbox.'],
                ]);
            }

            if (array_key_exists('gateway_merchant_code', $attributes)) {
                $exists = DokuMerchantCredential::query()
                    ->where('merchant_code', $merchantCode)
                    ->where('environment', 'sandbox')
                    ->exists();

                if (! $exists) {
                    throw ValidationException::withMessages([
                        'gateway_merchant_code' => ['Merchant DOKU tidak ditemukan pada sandbox.'],
                    ]);
                }
            }
        }

        $paymentGateway->update($attributes);

        return new ApplicationPaymentGatewayResource($paymentGateway->fresh()->load('application:id,code,name'));
    }

    public function destroy(ApplicationPaymentGateway $paymentGateway): JsonResponse
    {
        $this->authorize('delete', $paymentGateway);

        $paymentGateway->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Mapping payment gateway berhasil dihapus.',
        ]);
    }
}
