<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListInternalDanaGatewayMerchantsRequest;
use App\Http\Requests\RevokeDanaGatewayMerchantApiKeyRequest;
use App\Http\Requests\StoreDanaGatewayMerchantDanaConfigurationRequest;
use App\Http\Requests\StoreDanaGatewayMerchantRequest;
use App\Http\Requests\StoreDanaSandboxTestTransactionRequest;
use App\Http\Resources\DanaGatewayMerchantApiKeyResource;
use App\Http\Resources\DanaGatewayMerchantApiKeyRevocationResource;
use App\Http\Resources\DanaGatewayMerchantDanaConfigurationResource;
use App\Http\Resources\DanaGatewayMerchantReadinessResource;
use App\Http\Resources\DanaGatewayMerchantResource;
use App\Http\Resources\DanaGatewayMerchantStoredConfigurationResource;
use App\Models\DanaMerchantApiKeyIssuance;
use App\Models\DanaMerchantProvisioningAudit;
use App\Models\DanaSandboxResultContext;
use App\Models\User;
use App\Services\DanaMerchantApiKeyIssuanceService;
use App\Services\DanaMerchantProvisioningClient;
use App\Services\DanaMerchantProvisioningException;
use App\Services\DanaMerchantSecretStoreException;
use App\Services\DanaSandboxResultContextService;
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

    public function validateDanaSandboxTester(int $merchantId, Request $request, DanaMerchantProvisioningClient $client): JsonResponse
    {
        $merchant = $this->sandboxProvisionedMerchant($merchantId);
        if ($merchant === null) {
            return $this->merchantNotFound();
        }

        try {
            $validation = $client->validateDanaSandboxTester($merchantId);
        } catch (DanaMerchantProvisioningException $exception) {
            $this->recordManagementAudit($request, $merchant, 'dana_sandbox_tester.configuration_validated', 'failed', $exception->gatewayStatus, $exception->retryable, []);

            return $this->gatewayError($exception)->header('Cache-Control', 'no-store');
        }

        $this->recordManagementAudit($request, $merchant, 'dana_sandbox_tester.configuration_validated', $validation['format_valid'] ? 'succeeded' : 'failed', 200, false, [
            'format_valid' => $validation['format_valid'],
        ]);

        return response()->json(['data' => $validation])->header('Cache-Control', 'no-store');
    }

    public function testCreateOrderMissingTimestamp(int $merchantId, DanaMerchantProvisioningClient $client): JsonResponse
    {
        if ($this->sandboxProvisionedMerchant($merchantId) === null) {
            return $this->merchantNotFound();
        }

        try {
            $result = $client->testCreateOrderMissingTimestamp($merchantId);
        } catch (DanaMerchantProvisioningException $exception) {
            return $this->gatewayError($exception)->header('Cache-Control', 'no-store');
        }

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }

    public function testCreateOrderInvalidAmountFormat(int $merchantId, DanaMerchantProvisioningClient $client): JsonResponse
    {
        if ($this->sandboxProvisionedMerchant($merchantId) === null) {
            return $this->merchantNotFound();
        }

        try {
            $result = $client->testCreateOrderInvalidAmountFormat($merchantId);
        } catch (DanaMerchantProvisioningException $exception) {
            return $this->gatewayError($exception)->header('Cache-Control', 'no-store');
        }

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }

    public function testCreateOrderInconsistentRequest(int $merchantId, DanaMerchantProvisioningClient $client): JsonResponse
    {
        if ($this->sandboxProvisionedMerchant($merchantId) === null) {
            return $this->merchantNotFound();
        }

        try {
            $result = $client->testCreateOrderInconsistentRequest($merchantId);
        } catch (DanaMerchantProvisioningException $exception) {
            return $this->gatewayError($exception)->header('Cache-Control', 'no-store');
        }

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }

    public function testDebitStatusTransactionNotFound(int $merchantId, Request $request, DanaMerchantProvisioningClient $client): JsonResponse
    {
        $merchant = $this->sandboxProvisionedMerchant($merchantId);
        if ($merchant === null) {
            return $this->merchantNotFound();
        }

        try {
            $result = $client->testDebitStatusTransactionNotFound($merchantId);
        } catch (DanaMerchantProvisioningException $exception) {
            $this->recordManagementAudit($request, $merchant, 'dana_sandbox_tester.uat_debit_status_not_found', 'failed', $exception->gatewayStatus, $exception->retryable, [
                'original_partner_reference_no' => '12345678',
            ]);

            return $this->gatewayError($exception)->header('Cache-Control', 'no-store');
        }

        $uat = is_array($result['uat'] ?? null) ? $result['uat'] : [];
        $passed = ($uat['passed'] ?? false) === true;

        $this->recordManagementAudit($request, $merchant, 'dana_sandbox_tester.uat_debit_status_not_found', $passed ? 'succeeded' : 'failed', 200, false, [
            'original_partner_reference_no' => '12345678',
            'passed' => $passed,
            'response_code' => $uat['received']['response_code'] ?? null,
            'response_message' => $uat['received']['response_message'] ?? null,
            'request_id' => $uat['received']['request_id'] ?? null,
        ]);

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }

    public function testDebitStatusMissingTimestamp(int $merchantId, Request $request, DanaMerchantProvisioningClient $client): JsonResponse
    {
        $merchant = $this->sandboxProvisionedMerchant($merchantId);
        if ($merchant === null) {
            return $this->merchantNotFound();
        }

        try {
            $result = $client->testDebitStatusMissingTimestamp($merchantId);
        } catch (DanaMerchantProvisioningException $exception) {
            $this->recordManagementAudit($request, $merchant, 'dana_sandbox_tester.uat_debit_status_missing_timestamp', 'failed', $exception->gatewayStatus, $exception->retryable, [
                'x_timestamp_sent' => false,
            ]);

            return $this->gatewayError($exception)->header('Cache-Control', 'no-store');
        }

        $uat = is_array($result['uat'] ?? null) ? $result['uat'] : [];
        $passed = ($uat['passed'] ?? false) === true;

        $this->recordManagementAudit($request, $merchant, 'dana_sandbox_tester.uat_debit_status_missing_timestamp', $passed ? 'succeeded' : 'failed', 200, false, [
            'x_timestamp_sent' => false,
            'passed' => $passed,
            'response_code' => $uat['received']['response_code'] ?? null,
            'response_message' => $uat['received']['response_message'] ?? null,
            'request_id' => $uat['received']['request_id'] ?? null,
        ]);

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }

    public function testDebitStatusInternalServerError(int $merchantId, Request $request, DanaMerchantProvisioningClient $client): JsonResponse
    {
        $merchant = $this->sandboxProvisionedMerchant($merchantId);
        if ($merchant === null) {
            return $this->merchantNotFound();
        }

        try {
            $result = $client->testDebitStatusInternalServerError($merchantId);
        } catch (DanaMerchantProvisioningException $exception) {
            $this->recordManagementAudit($request, $merchant, 'dana_sandbox_tester.uat_debit_status_internal_server_error', 'failed', $exception->gatewayStatus, $exception->retryable, [
                'service_code' => 'AZ',
            ]);

            return $this->gatewayError($exception)->header('Cache-Control', 'no-store');
        }

        $uat = is_array($result['uat'] ?? null) ? $result['uat'] : [];
        $passed = ($uat['passed'] ?? false) === true;

        $this->recordManagementAudit($request, $merchant, 'dana_sandbox_tester.uat_debit_status_internal_server_error', $passed ? 'succeeded' : 'failed', 200, false, [
            'service_code' => 'AZ',
            'passed' => $passed,
            'response_code' => $uat['received']['response_code'] ?? null,
            'response_message' => $uat['received']['response_message'] ?? null,
            'request_id' => $uat['received']['request_id'] ?? null,
        ]);

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }

    public function testDebitStatusInvalidSignature(int $merchantId, Request $request, DanaMerchantProvisioningClient $client): JsonResponse
    {
        $merchant = $this->sandboxProvisionedMerchant($merchantId);
        if ($merchant === null) {
            return $this->merchantNotFound();
        }

        try {
            $result = $client->testDebitStatusInvalidSignature($merchantId);
        } catch (DanaMerchantProvisioningException $exception) {
            $this->recordManagementAudit($request, $merchant, 'dana_sandbox_tester.uat_debit_status_invalid_signature', 'failed', $exception->gatewayStatus, $exception->retryable, [
                'signature_override' => 'invalid_signature',
            ]);

            return $this->gatewayError($exception)->header('Cache-Control', 'no-store');
        }

        $uat = is_array($result['uat'] ?? null) ? $result['uat'] : [];
        $passed = ($uat['passed'] ?? false) === true;

        $this->recordManagementAudit($request, $merchant, 'dana_sandbox_tester.uat_debit_status_invalid_signature', $passed ? 'succeeded' : 'failed', 200, false, [
            'signature_override' => 'invalid_signature',
            'passed' => $passed,
            'response_code' => $uat['received']['response_code'] ?? null,
            'response_message' => $uat['received']['response_message'] ?? null,
            'request_id' => $uat['received']['request_id'] ?? null,
        ]);

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }

    public function armDanaSandboxWebhookSimulated500(int $merchantId, Request $request, DanaMerchantProvisioningClient $client): JsonResponse
    {
        if ($this->sandboxProvisionedMerchant($merchantId) === null) {
            return $this->merchantNotFound();
        }

        $paymentId = $request->input('payment_id');
        try {
            $result = $client->armDanaSandboxWebhookSimulated500($merchantId, is_string($paymentId) ? $paymentId : null);
        } catch (DanaMerchantProvisioningException $exception) {
            return $this->gatewayError($exception)->header('Cache-Control', 'no-store');
        }

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }

    public function createDanaSandboxTestTransaction(int $merchantId, StoreDanaSandboxTestTransactionRequest $request, DanaMerchantProvisioningClient $client, DanaSandboxResultContextService $contextService): JsonResponse
    {
        $merchant = $this->sandboxProvisionedMerchant($merchantId);
        if ($merchant === null) {
            return $this->merchantNotFound();
        }

        /** @var User $admin */
        $admin = $request->user();
        $preparedContext = $contextService->prepare($merchant, $admin, $request->idempotencyKey());
        $result = null;

        try {
            $result = $client->createDanaSandboxTestTransaction($merchantId, [
                ...$request->transactionAttributes(),
                'return_context' => $preparedContext['token'],
            ], $request->idempotencyKey());
            $returnContext = $result['return_context'] ?? null;
            if (! is_string($returnContext)) {
                throw new DanaMerchantProvisioningException('Konteks hasil tester Sandbox tidak tersedia.', 502, false);
            }
            $contextService->bind($preparedContext['context'], $result['transaction']['id'], $returnContext);
        } catch (DanaMerchantProvisioningException $exception) {
            $this->recordManagementAudit($request, $merchant, 'dana_sandbox_tester.transaction_created', 'failed', $exception->gatewayStatus, $exception->retryable, array_filter([
                'payment_id' => is_array($result) && is_array($result['transaction'] ?? null) && is_string($result['transaction']['id'] ?? null) ? $result['transaction']['id'] : null,
                ...$this->sandboxTransactionFailureDetails($exception),
            ]), $request->idempotencyKey());

            return $this->gatewayError($exception)->header('Cache-Control', 'no-store');
        }

        $this->recordManagementAudit($request, $merchant, 'dana_sandbox_tester.transaction_created', 'succeeded', 201, false, [
            'payment_id' => $result['transaction']['id'],
            'checkout_available' => $result['transaction']['checkout_available'],
            'payment_method' => $request->transactionAttributes()['payment_method'],
        ], $request->idempotencyKey());

        unset($result['return_context']);
        $result['result_context'] = $returnContext;

        return response()->json(['data' => $result], 201)->header('Cache-Control', 'no-store');
    }

    public function sandboxPaymentResult(string $context, Request $request, DanaMerchantProvisioningClient $client, DanaSandboxResultContextService $contextService): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $resultContext = $contextService->forReturn($context, $admin);

        return $this->sandboxPaymentResultResponse($resultContext->gateway_merchant_id, $resultContext->gateway_payment_id, $client);
    }

    public function sandboxPaymentResultFromHistory(int $merchantId, string $paymentId, Request $request, DanaMerchantProvisioningClient $client, DanaSandboxResultContextService $contextService): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $resultContext = $contextService->forHistory($merchantId, $paymentId, $admin);

        return $this->sandboxPaymentResultResponse($resultContext->gateway_merchant_id, $resultContext->gateway_payment_id, $client);
    }

    public function danaSandboxTestTransactionStatus(int $merchantId, string $paymentId, Request $request, DanaMerchantProvisioningClient $client): JsonResponse
    {
        $merchant = $this->sandboxProvisionedMerchant($merchantId);
        if ($merchant === null) {
            return $this->merchantNotFound();
        }

        try {
            $result = $client->danaSandboxTestTransactionStatus($merchantId, $paymentId);
        } catch (DanaMerchantProvisioningException $exception) {
            $this->recordManagementAudit($request, $merchant, 'dana_sandbox_tester.transaction_status_checked', 'failed', $exception->gatewayStatus, $exception->retryable, []);

            return $this->gatewayError($exception)->header('Cache-Control', 'no-store');
        }

        $this->recordManagementAudit($request, $merchant, 'dana_sandbox_tester.transaction_status_checked', 'succeeded', 200, false, [
            'payment_id' => $result['transaction']['id'],
            'payment_status' => $result['transaction']['status'],
        ]);

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }

    public function queryDanaSandboxPaymentStatus(int $merchantId, string $paymentId, Request $request, DanaMerchantProvisioningClient $client): JsonResponse
    {
        $merchant = $this->sandboxProvisionedMerchant($merchantId);
        if ($merchant === null) {
            return $this->merchantNotFound();
        }

        try {
            $result = $client->queryDanaSandboxPaymentStatus($merchantId, $paymentId);
        } catch (DanaMerchantProvisioningException $exception) {
            $this->recordManagementAudit($request, $merchant, 'dana_sandbox_tester.dana_payment_status_queried', 'failed', $exception->gatewayStatus, $exception->retryable, ['payment_id' => $paymentId]);

            return $this->gatewayError($exception)->header('Cache-Control', 'no-store');
        }

        $this->recordManagementAudit($request, $merchant, 'dana_sandbox_tester.dana_payment_status_queried', 'succeeded', 200, false, [
            'payment_id' => $result['payment_id'],
            'local_status' => $result['local_status'],
            'dana_http_status' => $result['dana_status']['http_status'],
            'dana_response_code' => $result['dana_status']['response_code'],
            'dana_latest_transaction_status' => $result['dana_status']['latest_transaction_status'],
            'dana_request_id' => $result['dana_status']['request_id'],
        ]);

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }

    public function danaSandboxTestHistory(int $merchantId, Request $request, DanaMerchantProvisioningClient $client): JsonResponse
    {
        $merchant = $this->sandboxProvisionedMerchant($merchantId);
        if ($merchant === null) {
            return $this->merchantNotFound();
        }

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        try {
            $result = $client->danaSandboxTestHistory($merchantId, $validated);
        } catch (DanaMerchantProvisioningException $exception) {
            return $this->gatewayError($exception)->header('Cache-Control', 'no-store');
        }

        return response()->json(['data' => $result['runs'], 'meta' => $result['meta']])->header('Cache-Control', 'no-store');
    }

    public function danaSandboxPayments(int $merchantId, Request $request, DanaMerchantProvisioningClient $client): JsonResponse
    {
        $merchant = $this->sandboxProvisionedMerchant($merchantId);
        if ($merchant === null) {
            return $this->merchantNotFound();
        }

        $validated = $this->paymentAuditFilters($request);
        $paymentIds = $this->ownedSandboxPaymentIds($merchantId, $request);
        if ($paymentIds === []) {
            return response()->json(['data' => [], 'meta' => ['current_page' => $validated['page'] ?? 1, 'per_page' => $validated['per_page'] ?? 15, 'total' => 0, 'last_page' => 1]])->header('Cache-Control', 'no-store');
        }

        try {
            $result = $client->paymentAuditList($merchantId, [...$validated, 'payment_ids' => $paymentIds]);
        } catch (DanaMerchantProvisioningException $exception) {
            return $this->gatewayError($exception)->header('Cache-Control', 'no-store');
        }

        return response()->json(['data' => $result['payments'], 'meta' => $result['meta']])->header('Cache-Control', 'no-store');
    }

    public function danaSandboxPaymentDetail(int $merchantId, string $paymentId, Request $request, DanaMerchantProvisioningClient $client): JsonResponse
    {
        $merchant = $this->sandboxProvisionedMerchant($merchantId);
        if ($merchant === null) {
            return $this->merchantNotFound();
        }

        if (! $this->ownsSandboxPayment($merchantId, $paymentId, $request)) {
            return response()->json(['message' => 'Transaksi Sandbox tidak dapat diakses oleh admin ini.'], 403);
        }

        try {
            return response()->json(['data' => $client->paymentAudit($merchantId, $paymentId)])
                ->header('Cache-Control', 'no-store');
        } catch (DanaMerchantProvisioningException $exception) {
            return $this->gatewayError($exception)->header('Cache-Control', 'no-store');
        }
    }

    public function danaSandboxPaymentWebhooks(int $merchantId, string $paymentId, Request $request, DanaMerchantProvisioningClient $client): JsonResponse
    {
        if ($this->sandboxProvisionedMerchant($merchantId) === null) {
            return $this->merchantNotFound();
        }
        if (! $this->ownsSandboxPayment($merchantId, $paymentId, $request)) {
            return response()->json(['message' => 'Transaksi Sandbox tidak dapat diakses oleh admin ini.'], 403);
        }
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        try {
            $result = $client->paymentAuditWebhooks($merchantId, $paymentId, $validated);

            return response()->json(['data' => $result['webhooks'], 'meta' => $result['meta']])->header('Cache-Control', 'no-store');
        } catch (DanaMerchantProvisioningException $exception) {
            return $this->gatewayError($exception)->header('Cache-Control', 'no-store');
        }
    }

    public function danaSandboxPaymentSummary(int $merchantId, Request $request, DanaMerchantProvisioningClient $client): JsonResponse
    {
        if ($this->sandboxProvisionedMerchant($merchantId) === null) {
            return $this->merchantNotFound();
        }
        $paymentIds = $this->ownedSandboxPaymentIds($merchantId, $request);
        if ($paymentIds === []) {
            return response()->json(['data' => ['total' => 0, 'paid' => 0, 'pending' => 0, 'failed_or_expired' => 0, 'paid_amounts' => []]])->header('Cache-Control', 'no-store');
        }
        try {
            return response()->json(['data' => $client->paymentAuditSummary($merchantId, [...$this->paymentAuditFilters($request), 'payment_ids' => $paymentIds])])->header('Cache-Control', 'no-store');
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
        $response = response()->json(['message' => $exception->getMessage()], $exception->status);

        if ($exception->retryAfter !== null) {
            $response->headers->set('Retry-After', $exception->retryAfter);
        }

        return $response;
    }

    /** @return array{error_context: string, retryable: bool} */
    private function sandboxTransactionFailureDetails(DanaMerchantProvisioningException $exception): array
    {
        $context = match ($exception->getMessage()) {
            'Konflik idempotensi transaksi Sandbox: key hanya dapat dipakai ulang untuk payload yang identik.' => 'idempotency_conflict',
            'Konflik referensi transaksi Sandbox: referensi order sudah digunakan oleh transaksi lain.' => 'merchant_order_conflict',
            'Konflik penyimpanan transaksi Sandbox. Coba buat tindakan baru.' => 'transaction_storage_conflict',
            default => $exception->status === 409 ? 'transaction_conflict' : 'transaction_request_failed',
        };

        return ['error_context' => $context, 'retryable' => $exception->retryable];
    }

    private function sandboxPaymentResultResponse(int $merchantId, string $paymentId, DanaMerchantProvisioningClient $client): JsonResponse
    {
        $merchant = $this->sandboxProvisionedMerchant($merchantId);
        if ($merchant === null) {
            return $this->merchantNotFound();
        }

        try {
            $result = $client->danaSandboxTestTransactionStatus($merchantId, $paymentId);
        } catch (DanaMerchantProvisioningException $exception) {
            return $this->gatewayError($exception)->header('Cache-Control', 'no-store');
        }

        $transaction = $result['transaction'];
        if (($transaction['id'] ?? null) !== $paymentId) {
            return response()->json(['message' => 'Data transaksi Sandbox gateway tidak cocok.'], 502)->header('Cache-Control', 'no-store');
        }

        return response()->json(['data' => [
            'merchant' => $merchant->merchant_code,
            'transaction' => collect($transaction)->only(['id', 'merchant_order_id', 'amount_minor', 'currency', 'status', 'created_at', 'updated_at'])->all(),
        ]])->header('Cache-Control', 'no-store');
    }

    /** @return array<string, mixed> */
    private function paymentAuditFilters(Request $request): array
    {
        return $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'in:pending,paid,failed,expired,cancelled'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'merchant_order_id' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /** @return list<string> */
    private function ownedSandboxPaymentIds(int $merchantId, Request $request): array
    {
        return DanaSandboxResultContext::query()
            ->where('admin_user_id', $request->user()->getAuthIdentifier())
            ->where('gateway_merchant_id', $merchantId)
            ->whereNotNull('gateway_payment_id')
            ->latest('id')
            ->limit(500)
            ->pluck('gateway_payment_id')
            ->filter(fn (mixed $paymentId): bool => is_string($paymentId) && $paymentId !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function ownsSandboxPayment(int $merchantId, string $paymentId, Request $request): bool
    {
        return DanaSandboxResultContext::query()
            ->where('admin_user_id', $request->user()->getAuthIdentifier())
            ->where('gateway_merchant_id', $merchantId)
            ->where('gateway_payment_id', $paymentId)
            ->exists();
    }

    /** @return array<string, mixed> */
    private function sandboxPaymentData(DanaMerchantProvisioningAudit $merchant, string $paymentId, DanaMerchantProvisioningClient $client): array
    {
        $result = $client->danaSandboxTestTransactionStatus($merchant->gateway_merchant_id, $paymentId);
        $transaction = $result['transaction'];
        if (($transaction['id'] ?? null) !== $paymentId) {
            throw new DanaMerchantProvisioningException('Data transaksi Sandbox gateway tidak cocok.', 502, false);
        }

        return [
            'merchant' => $merchant->merchant_code,
            'application' => null,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'status_source' => 'gateway_verified_payment_record',
            'transaction' => collect($transaction)->only([
                'id',
                'merchant_order_id',
                'amount_minor',
                'currency',
                'status',
                'created_at',
                'updated_at',
            ])->all(),
        ];
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
