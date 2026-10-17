<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListInternalDanaGatewayMerchantsRequest;
use App\Http\Requests\RevokeDanaGatewayMerchantApiKeyRequest;
use App\Http\Requests\StoreDanaGatewayMerchantDanaConfigurationRequest;
use App\Http\Requests\StoreDanaGatewayMerchantRequest;
use App\Http\Resources\DanaGatewayMerchantApiKeyResource;
use App\Http\Resources\DanaGatewayMerchantApiKeyRevocationResource;
use App\Http\Resources\DanaGatewayMerchantDanaConfigurationResource;
use App\Http\Resources\DanaGatewayMerchantReadinessResource;
use App\Http\Resources\DanaGatewayMerchantResource;
use App\Http\Resources\DanaGatewayMerchantStoredConfigurationResource;
use App\Models\DanaMerchantApiKeyIssuance;
use App\Models\DanaMerchantProvisioningAudit;
use App\Services\DanaMerchantApiKeyIssuanceService;
use App\Services\DanaMerchantProvisioningClient;
use App\Services\DanaMerchantProvisioningException;
use App\Services\DanaMerchantSecretStoreException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

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

    public function readiness(int $merchantId, Request $request, DanaMerchantProvisioningClient $client): DanaGatewayMerchantReadinessResource|JsonResponse
    {
        $merchant = $this->sandboxProvisionedMerchant($merchantId);
        if ($merchant === null) {
            return $this->merchantNotFound();
        }

        try {
            $readiness = $client->readiness($merchantId);
        } catch (DanaMerchantProvisioningException $exception) {
            $this->recordManagementAudit($request, $merchant, 'dana_merchant.readiness_viewed', 'failed', $exception->gatewayStatus, $exception->retryable, [
                'retryable' => $exception->retryable,
            ]);

            return $this->gatewayError($exception);
        }

        $this->recordManagementAudit($request, $merchant, 'dana_merchant.readiness_viewed', 'succeeded', 200, false, [
            'readiness_status' => $readiness['readiness_status'],
            'active_api_key_count' => $readiness['active_api_key_count'],
            'dana_configuration_ready' => $readiness['dana_configuration']['ready'],
        ]);

        return new DanaGatewayMerchantReadinessResource($readiness);
    }

    public function configureDanaSandbox(
        int $merchantId,
        StoreDanaGatewayMerchantDanaConfigurationRequest $request,
        DanaMerchantProvisioningClient $client,
    ): DanaGatewayMerchantDanaConfigurationResource|JsonResponse {
        $merchant = $this->sandboxProvisionedMerchant($merchantId);
        if ($merchant === null) {
            return $this->merchantNotFound();
        }

        /** @var array{environment: string, partner_id: string, merchant_id: string, private_key: string, dana_webhook_public_key: string, origin: string, channel_id: string, notification_url: string, callback_url: string, mcc: string, enabled: bool} $attributes */
        $attributes = $request->validated();
        $idempotencyKey = $client->stableDanaConfigurationIdempotencyKey($merchantId, $attributes);

        try {
            $configuration = $client->configureDanaSandbox($merchantId, $attributes, $idempotencyKey);
        } catch (DanaMerchantProvisioningException $exception) {
            $this->recordManagementAudit($request, $merchant, 'dana_merchant.dana_configuration_updated', 'failed', $exception->gatewayStatus, $exception->retryable, [
                'retryable' => $exception->retryable,
            ], $idempotencyKey);

            return $this->gatewayError($exception);
        }

        $this->recordManagementAudit($request, $merchant, 'dana_merchant.dana_configuration_updated', 'succeeded', 200, false, [
            'dana_configuration_available' => $configuration['dana_configuration']['available'],
            'dana_configuration_enabled' => $configuration['dana_configuration']['enabled'],
            'dana_configuration_ready' => $configuration['dana_configuration']['ready'],
        ], $idempotencyKey);

        return new DanaGatewayMerchantDanaConfigurationResource($configuration);
    }

    public function storedDanaConfiguration(int $merchantId, DanaMerchantProvisioningClient $client): DanaGatewayMerchantStoredConfigurationResource|JsonResponse
    {
        if ($this->sandboxProvisionedMerchant($merchantId) === null) {
            return $this->merchantNotFound();
        }

        try {
            return (new DanaGatewayMerchantStoredConfigurationResource($client->storedDanaConfiguration($merchantId)))
                ->response()
                ->header('Cache-Control', 'no-store');
        } catch (DanaMerchantProvisioningException $exception) {
            return $this->gatewayError($exception)->header('Cache-Control', 'no-store');
        }
    }

    public function apiKeys(int $merchantId, Request $request, DanaMerchantProvisioningClient $client): AnonymousResourceCollection|JsonResponse
    {
        $merchant = $this->sandboxProvisionedMerchant($merchantId);
        if ($merchant === null) {
            return $this->merchantNotFound();
        }

        try {
            $apiKeys = $client->apiKeys($merchantId);
        } catch (DanaMerchantProvisioningException $exception) {
            $this->recordManagementAudit($request, $merchant, 'dana_merchant.api_keys_viewed', 'failed', $exception->gatewayStatus, $exception->retryable, [
                'retryable' => $exception->retryable,
            ]);

            return $this->gatewayError($exception);
        }

        $this->recordManagementAudit($request, $merchant, 'dana_merchant.api_keys_viewed', 'succeeded', 200, false, [
            'api_key_count' => count($apiKeys),
        ]);

        return DanaGatewayMerchantApiKeyResource::collection($apiKeys);
    }

    public function revokeApiKey(
        int $merchantId,
        int $apiKeyId,
        RevokeDanaGatewayMerchantApiKeyRequest $request,
        DanaMerchantProvisioningClient $client,
    ): DanaGatewayMerchantApiKeyRevocationResource|JsonResponse {
        $merchant = $this->sandboxProvisionedMerchant($merchantId);
        if ($merchant === null) {
            return $this->merchantNotFound();
        }

        $idempotencyKey = $client->stableApiKeyRevocationIdempotencyKey($merchantId, $apiKeyId);

        try {
            $apiKey = $client->revokeApiKey($merchantId, $apiKeyId, $idempotencyKey);
        } catch (DanaMerchantProvisioningException $exception) {
            $this->recordManagementAudit($request, $merchant, 'dana_merchant.api_key_revoked', 'failed', $exception->gatewayStatus, $exception->retryable, [
                'api_key_id' => $apiKeyId,
                'retryable' => $exception->retryable,
            ], $idempotencyKey);

            return $this->gatewayError($exception);
        }

        $this->recordManagementAudit($request, $merchant, 'dana_merchant.api_key_revoked', 'succeeded', 200, false, [
            'api_key_id' => $apiKey['id'],
            'key_prefix' => $apiKey['key_prefix'],
            'revoked_at' => $apiKey['revoked_at'],
        ], $idempotencyKey);

        return new DanaGatewayMerchantApiKeyRevocationResource($apiKey);
    }

    public function issueApiKey(int $merchantId, Request $request, DanaMerchantApiKeyIssuanceService $service): JsonResponse
    {
        $merchant = $this->sandboxProvisionedMerchant($merchantId);
        if ($merchant === null) {
            return $this->merchantNotFound();
        }
        $attributes = $request->validate(['environment' => ['required', 'in:sandbox'], 'name' => ['required', 'string', 'max:120'], 'abilities' => ['required', 'array', 'min:1'], 'abilities.*' => ['string', 'in:payments:create,payments:read,payments:*'], 'expires_at' => ['nullable', 'date', 'after:now']]);
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{15,127}\z/', $idempotencyKey)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Idempotency-Key wajib berupa identifier tindakan yang valid.']);
        }

        try {
            $issuance = $service->issue($merchant, (int) $request->user()->getAuthIdentifier(), $attributes, $idempotencyKey);

            return response()->json(['data' => $this->issuanceData($issuance)])->header('Cache-Control', 'no-store');
        } catch (DanaMerchantProvisioningException|DanaMerchantSecretStoreException $e) {
            return ($e instanceof DanaMerchantSecretStoreException
                ? $this->secretStoreError()
                : $this->gatewayError($e))->header('Cache-Control', 'no-store');
        }
    }

    public function issuanceStatus(int $merchantId, int $issuanceId, DanaMerchantApiKeyIssuanceService $service): JsonResponse
    {
        $issuance = DanaMerchantApiKeyIssuance::query()->where('gateway_merchant_id', $merchantId)->find($issuanceId);
        if ($issuance === null || $this->sandboxProvisionedMerchant($merchantId) === null) {
            return $this->merchantNotFound();
        } try {
            return response()->json(['data' => $this->issuanceData($service->reconcile($issuance))])->header('Cache-Control', 'no-store');
        } catch (DanaMerchantProvisioningException $e) {
            return $this->gatewayError($e)->header('Cache-Control', 'no-store');
        }
    }

    public function issuances(int $merchantId, Request $request): JsonResponse
    {
        $merchant = $this->sandboxProvisionedMerchant($merchantId);
        if ($merchant === null) {
            return $this->merchantNotFound();
        }

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'status' => ['nullable', 'in:pending,secret_stored,confirmation_pending,active,cancel_pending,cancelled,expired,manual_review'],
        ]);
        $issuances = DanaMerchantApiKeyIssuance::query()
            ->where('gateway_merchant_id', $merchantId)
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate($validated['per_page'] ?? 15);

        $this->recordManagementAudit($request, $merchant, 'dana_merchant.api_key_issuances_viewed', 'succeeded', 200, false, [
            'issuance_count' => $issuances->count(),
            'status_filter' => $validated['status'] ?? null,
        ]);

        return response()->json([
            'data' => $issuances->getCollection()->map(fn (DanaMerchantApiKeyIssuance $issuance): array => $this->issuanceData($issuance))->values(),
            'meta' => [
                'current_page' => $issuances->currentPage(),
                'per_page' => $issuances->perPage(),
                'total' => $issuances->total(),
                'last_page' => $issuances->lastPage(),
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function cancelIssuance(int $merchantId, int $issuanceId, DanaMerchantApiKeyIssuanceService $service): JsonResponse
    {
        $issuance = DanaMerchantApiKeyIssuance::query()->where('gateway_merchant_id', $merchantId)->find($issuanceId);
        if ($issuance === null || $this->sandboxProvisionedMerchant($merchantId) === null) {
            return $this->merchantNotFound();
        } try {
            return response()->json(['data' => $this->issuanceData($service->cancel($issuance))])->header('Cache-Control', 'no-store');
        } catch (DanaMerchantProvisioningException $e) {
            return $this->gatewayError($e)->header('Cache-Control', 'no-store');
        }
    }

    private function issuanceData(DanaMerchantApiKeyIssuance $issuance): array
    {
        return ['id' => $issuance->id, 'merchant_id' => $issuance->gateway_merchant_id, 'merchant_code' => $issuance->merchant_code, 'status' => $issuance->status, 'key_prefix' => $issuance->key_prefix, 'gateway_issuance_id' => $issuance->gateway_issuance_id, 'gateway_api_key_id' => $issuance->gateway_api_key_id, 'created_at' => $issuance->created_at];
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

    private function secretStoreError(): JsonResponse
    {
        return response()->json(['message' => 'Penyimpanan aman API key belum tersedia. Issuance tidak dikonfirmasi.'], 503);
    }

    private function sandboxProvisionedMerchant(int $merchantId): ?DanaMerchantProvisioningAudit
    {
        return DanaMerchantProvisioningAudit::query()
            ->where('operation', 'dana_merchant.provision')
            ->where('gateway_merchant_id', $merchantId)
            ->where('environment', 'sandbox')
            ->where('status', 'succeeded')
            ->where('merchant_code', '!=', 'larissama')
            ->latest('id')
            ->first();
    }

    private function merchantNotFound(): JsonResponse
    {
        return response()->json(['message' => 'Merchant gateway tidak ditemukan.'], 404);
    }

    /**
     * @param  array<string, bool|int|string|null>  $safeDetails
     */
    private function recordManagementAudit(
        Request $request,
        DanaMerchantProvisioningAudit $merchant,
        string $operation,
        string $status,
        ?int $gatewayStatus,
        bool $retryable,
        array $safeDetails,
        ?string $idempotencyKey = null,
    ): void {
        DanaMerchantProvisioningAudit::query()->create([
            'admin_user_id' => (int) $request->user()->getAuthIdentifier(),
            'operation' => $operation,
            'merchant_code' => $merchant->merchant_code,
            'gateway_merchant_id' => $merchant->gateway_merchant_id,
            'environment' => 'sandbox',
            'idempotency_fingerprint' => hash('sha256', $idempotencyKey ?? "{$operation}:{$merchant->gateway_merchant_id}"),
            'status' => $status,
            'gateway_status' => $gatewayStatus,
            'safe_details' => $safeDetails,
        ]);
    }
}
