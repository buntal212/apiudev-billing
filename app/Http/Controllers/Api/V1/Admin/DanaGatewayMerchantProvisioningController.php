<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListInternalDanaGatewayMerchantsRequest;
use App\Http\Requests\StoreDanaGatewayMerchantRequest;
use App\Http\Resources\DanaGatewayMerchantResource;
use App\Models\DanaMerchantProvisioningAudit;
use App\Services\DanaMerchantProvisioningClient;
use App\Services\DanaMerchantProvisioningException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DanaGatewayMerchantProvisioningController extends Controller
{
    public function index(
        ListInternalDanaGatewayMerchantsRequest $request,
        DanaMerchantProvisioningClient $client,
    ): AnonymousResourceCollection|JsonResponse {
        try {
            $result = $client->list($request->validated());
        } catch (DanaMerchantProvisioningException $exception) {
            return $this->gatewayError($exception);
        }

        return DanaGatewayMerchantResource::collection($result['merchants'])
            ->additional(['meta' => $result['meta']]);
    }

    public function store(
        StoreDanaGatewayMerchantRequest $request,
        DanaMerchantProvisioningClient $client,
    ): JsonResponse {
        $attributes = $request->validated();
        $idempotencyKey = $client->stableIdempotencyKey($attributes);

        try {
            $result = $client->provision($attributes, $idempotencyKey);
        } catch (DanaMerchantProvisioningException $exception) {
            $this->recordAudit($request, $attributes, $idempotencyKey, 'failed', null, $exception->gatewayStatus, $exception->retryable);

            return $this->gatewayError($exception);
        }

        $this->recordAudit(
            $request,
            $attributes,
            $idempotencyKey,
            'succeeded',
            $result['merchant'],
            $result['status'],
            false,
        );

        return (new DanaGatewayMerchantResource($result['merchant']))
            ->response()
            ->setStatusCode($result['status']);
    }

    public function show(int $merchantId, DanaMerchantProvisioningClient $client): DanaGatewayMerchantResource|JsonResponse
    {
        try {
            return new DanaGatewayMerchantResource($client->find($merchantId));
        } catch (DanaMerchantProvisioningException $exception) {
            return $this->gatewayError($exception);
        }
    }

    /**
     * @param  array{code: string, name: string, email: string|null, environment: string}  $attributes
     * @param  array{merchant_id: int, merchant_code: string, merchant_name: string, environment: string, merchant_status: string, provisioning_status: string, credential_status: string, dana_configuration_status: string, provisioned_at: string|null}|null  $merchant
     */
    private function recordAudit(
        Request $request,
        array $attributes,
        string $idempotencyKey,
        string $status,
        ?array $merchant,
        ?int $gatewayStatus,
        bool $retryable,
    ): void {
        DanaMerchantProvisioningAudit::query()->create([
            'admin_user_id' => (int) $request->user()->getAuthIdentifier(),
            'operation' => 'dana_merchant.provision',
            'merchant_code' => $attributes['code'],
            'gateway_merchant_id' => $merchant['merchant_id'] ?? null,
            'environment' => $attributes['environment'],
            'idempotency_fingerprint' => hash('sha256', $idempotencyKey),
            'status' => $status,
            'gateway_status' => $gatewayStatus,
            'safe_details' => array_filter([
                'provisioning_status' => $merchant['provisioning_status'] ?? null,
                'credential_status' => $merchant['credential_status'] ?? null,
                'dana_configuration_status' => $merchant['dana_configuration_status'] ?? null,
                'retryable' => $retryable,
            ], fn (mixed $value): bool => $value !== null),
        ]);
    }

    private function gatewayError(DanaMerchantProvisioningException $exception): JsonResponse
    {
        return response()->json(['message' => $exception->getMessage()], $exception->status);
    }
}
