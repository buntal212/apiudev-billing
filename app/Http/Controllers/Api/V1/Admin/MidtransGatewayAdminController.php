<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApplicationPaymentGateway;
use App\Models\MidtransGatewayAudit;
use App\Models\MidtransMerchantCredential;
use App\Models\MidtransPaymentMethodFee;
use App\Models\PaymentAttempt;
use App\Services\MidtransAuditService;
use App\Services\MidtransFeeService;
use App\Services\MidtransGatewayProvisioningClient;
use App\Services\MidtransSecretStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use App\Services\MidtransEnvironmentService;
use Illuminate\Validation\Rule;

class MidtransGatewayAdminController extends Controller
{
    public function __construct(
        private MidtransSecretStore $secretStore,
        private MidtransGatewayProvisioningClient $gatewayClient,
        private MidtransAuditService $auditService,
        private ?MidtransFeeService $feeService = null,
        private ?MidtransEnvironmentService $environmentService = null,
    ) {
        $this->feeService = $feeService ?? app(MidtransFeeService::class);
        $this->environmentService = $environmentService ?? app(MidtransEnvironmentService::class);
    }

    public function indexMerchants(Request $request): JsonResponse
    {
        // Ensure default merchants exist for both environments
        MidtransMerchantCredential::query()->firstOrCreate(
            ['merchant_code' => 'udev-central', 'environment' => 'sandbox'],
            ['merchant_name' => 'UDEV Central Billing', 'is_active' => true]
        );
        MidtransMerchantCredential::query()->firstOrCreate(
            ['merchant_code' => 'larissama', 'environment' => 'sandbox'],
            ['merchant_name' => 'Larissama', 'is_active' => false]
        );
        MidtransMerchantCredential::query()->firstOrCreate(
            ['merchant_code' => 'udev-central', 'environment' => 'production'],
            ['merchant_name' => 'UDEV Central Billing', 'is_active' => true]
        );

        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');
        $environment = (string) $request->query('environment', 'sandbox');

        $query = MidtransMerchantCredential::query();
        if ($environment !== 'all') {
            $query->where('environment', in_array($environment, ['sandbox', 'production'], true) ? $environment : 'sandbox');
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('merchant_code', 'like', "%{$search}%")
                    ->orWhere('merchant_name', 'like', "%{$search}%");
            });
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        } elseif ($status === 'configured') {
            $query->whereNotNull('encrypted_server_key');
        } elseif ($status === 'unconfigured') {
            $query->whereNull('encrypted_server_key');
        }

        $merchants = $query->orderBy('id')->get();

        $mappingsQuery = ApplicationPaymentGateway::query()
            ->with('application:id,code,name')
            ->where('provider', 'midtrans');

        if ($environment !== 'all') {
            $mappingsQuery->where('environment', in_array($environment, ['sandbox', 'production'], true) ? $environment : 'sandbox');
        }

        $mappings = $mappingsQuery->get()->groupBy('gateway_merchant_code');

        $data = $merchants->map(function (MidtransMerchantCredential $cred) use ($mappings) {
            $hasServerKey = ! empty($cred->encrypted_server_key);
            $hasClientKey = ! empty($cred->client_key);
            $isActive = (bool) $cred->is_active;
            $isReady = $hasServerKey && $isActive;
            $isConfigured = $hasServerKey;
            $merchantMappings = $mappings->get($cred->merchant_code, collect());

            return [
                'id' => $cred->id,
                'merchant_code' => $cred->merchant_code,
                'merchant_name' => $cred->merchant_name,
                'midtrans_merchant_id' => (string) ($cred->midtrans_merchant_id ?? ''),
                'client_key' => (string) ($cred->client_key ?? ''),
                'masked_client_key' => $cred->masked_client_key,
                'environment' => $cred->environment,
                'is_configured' => $isConfigured,
                'is_ready' => $isReady,
                'is_active' => $isActive,
                'has_server_key' => $hasServerKey,
                'has_client_key' => $hasClientKey,
                'has_signature_key' => ! empty($cred->encrypted_signature_key),
                'last_tested_at' => $cred->last_tested_at?->format('Y-m-d H:i:s'),
                'last_test_status' => $cred->last_test_status,
                'last_test_message' => $cred->last_test_message,
                'application_mappings_count' => $merchantMappings->count(),
                'application_names' => $merchantMappings->pluck('application.name')->filter()->values()->all(),
                'created_at' => $cred->created_at?->format('Y-m-d H:i:s'),
                'updated_at' => $cred->updated_at?->format('Y-m-d H:i:s'),
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function storeMerchant(Request $request): JsonResponse
    {
        $environment = (string) $request->input('environment', 'sandbox');
        $environment = in_array($environment, ['sandbox', 'production'], true) ? $environment : 'sandbox';

        $validated = $request->validate([
            'merchant_code' => [
                'required',
                'string',
                'max:60',
                'regex:/^[a-z0-9_-]+$/',
                Rule::unique('midtrans_merchant_credentials', 'merchant_code')
                    ->where('environment', $environment),
            ],
            'merchant_name' => ['required', 'string', 'max:100'],
            'midtrans_merchant_id' => ['nullable', 'string', 'max:100'],
            'client_key' => ['nullable', 'string', 'max:150'],
            'environment' => ['nullable', 'string', 'in:sandbox,production'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $merchant = MidtransMerchantCredential::create([
            'merchant_code' => $validated['merchant_code'],
            'merchant_name' => $validated['merchant_name'],
            'midtrans_merchant_id' => $validated['midtrans_merchant_id'] ?? null,
            'client_key' => $validated['client_key'] ?? null,
            'environment' => $environment,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        $this->auditService->record(
            merchantCode: $merchant->merchant_code,
            action: 'merchant.created',
            status: 'success',
            metadata: ['merchant_code' => $merchant->merchant_code, 'merchant_name' => $merchant->merchant_name, 'environment' => $environment]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Merchant Midtrans berhasil ditambahkan.',
            'data' => $merchant,
        ], Response::HTTP_CREATED);
    }

    public function showMerchant(Request $request, string $merchantCode): JsonResponse
    {
        $environment = (string) $request->query('environment', 'sandbox');
        $merchant = MidtransMerchantCredential::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', $environment)
            ->firstOrFail();

        return response()->json(['data' => $merchant]);
    }

    public function updateMerchant(Request $request, string $merchantCode): JsonResponse
    {
        $environment = (string) ($request->input('environment') ?: $request->query('environment', 'sandbox'));
        $merchant = MidtransMerchantCredential::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', $environment)
            ->firstOrFail();

        $validated = $request->validate([
            'merchant_name' => ['sometimes', 'required', 'string', 'max:100'],
            'midtrans_merchant_id' => ['nullable', 'string', 'max:100'],
            'client_key' => ['nullable', 'string', 'max:150'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);

        $merchant->update($validated);

        $this->auditService->record(
            merchantCode: $merchant->merchant_code,
            action: 'merchant.updated',
            status: 'success',
            metadata: array_merge($validated, ['environment' => $environment])
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Data merchant Midtrans berhasil diperbarui.',
            'data' => $merchant,
        ]);
    }

    public function toggleMerchantStatus(Request $request, string $merchantCode): JsonResponse
    {
        $environment = (string) ($request->input('environment') ?: $request->query('environment', 'sandbox'));
        $merchant = MidtransMerchantCredential::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', $environment)
            ->firstOrFail();

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $merchant->update(['is_active' => $validated['is_active']]);

        $this->auditService->record(
            merchantCode: $merchant->merchant_code,
            action: $validated['is_active'] ? 'merchant.activated' : 'merchant.deactivated',
            status: 'success',
            metadata: ['environment' => $environment]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Status merchant berhasil diperbarui.',
            'data' => $merchant,
        ]);
    }

    public function destroyMerchant(Request $request, string $merchantCode): JsonResponse
    {
        $environment = (string) ($request->input('environment') ?: $request->query('environment', 'sandbox'));
        $merchant = MidtransMerchantCredential::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', $environment)
            ->firstOrFail();

        $mappingCount = ApplicationPaymentGateway::query()
            ->where('provider', 'midtrans')
            ->where('environment', $environment)
            ->where('gateway_merchant_code', $merchantCode)
            ->count();

        if ($mappingCount > 0) {
            return response()->json([
                'status' => 'error',
                'message' => "Merchant tidak dapat dihapus karena masih digunakan oleh {$mappingCount} mapping aplikasi.",
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $merchant->delete();

        $this->auditService->record(
            merchantCode: $merchantCode,
            action: 'merchant.deleted',
            status: 'success',
            metadata: ['environment' => $environment]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Merchant Midtrans berhasil dihapus.',
        ]);
    }

    public function showConfiguration(Request $request): JsonResponse
    {
        $merchantCode = (string) $request->query('merchant_code', 'udev-central');
        $environment = (string) $request->query('environment', 'sandbox');
        $environment = in_array($environment, ['sandbox', 'production'], true) ? $environment : 'sandbox';

        $cred = MidtransMerchantCredential::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', $environment)
            ->first();

        if ($cred === null) {
            return response()->json(['data' => null]);
        }

        $hasServerKey = ! empty($cred->encrypted_server_key);
        $hasSignatureKey = ! empty($cred->encrypted_signature_key);

        $maskedServerKey = null;
        if ($hasServerKey) {
            try {
                $decrypted = $this->secretStore->decryptSecret(
                    $cred->encrypted_server_key,
                    $cred->server_key_nonce,
                    $cred->server_key_tag,
                    $cred->merchant_code,
                    'server_key',
                    $cred->key_version ?? 'v1',
                    $cred->environment ?? 'sandbox',
                );
                $len = strlen($decrypted);
                $maskedServerKey = $len <= 8 ? str_repeat('*', $len) : substr($decrypted, 0, 8).str_repeat('*', max(4, $len - 12)).substr($decrypted, -4);
            } catch (\Throwable) {
                $maskedServerKey = ($environment === 'production' ? 'Mid-server-********' : 'SB-Mid-server-********');
            }
        }

        $maskedSignatureKey = null;
        if ($hasSignatureKey) {
            try {
                $decrypted = $this->secretStore->decryptSecret(
                    $cred->encrypted_signature_key,
                    $cred->signature_key_nonce,
                    $cred->signature_key_tag,
                    $cred->merchant_code,
                    'signature_key',
                    $cred->key_version ?? 'v1',
                    $cred->environment ?? 'sandbox',
                );
                $len = strlen($decrypted);
                $maskedSignatureKey = $len <= 8 ? str_repeat('*', $len) : substr($decrypted, 0, 4).str_repeat('*', max(4, $len - 8)).substr($decrypted, -4);
            } catch (\Throwable) {
                $maskedSignatureKey = '********';
            }
        }

        return response()->json([
            'data' => [
                'merchant_code' => $cred->merchant_code,
                'merchant_name' => $cred->merchant_name,
                'midtrans_merchant_id' => (string) ($cred->midtrans_merchant_id ?? ''),
                'client_key' => (string) ($cred->client_key ?? ''),
                'masked_client_key' => $cred->masked_client_key,
                'masked_server_key' => $maskedServerKey,
                'masked_signature_key' => $maskedSignatureKey,
                'has_server_key' => $hasServerKey,
                'has_signature_key' => $hasSignatureKey,
                'is_active' => (bool) $cred->is_active,
                'environment' => $cred->environment,
                'last_tested_at' => $cred->last_tested_at?->format('Y-m-d H:i:s'),
                'last_test_status' => $cred->last_test_status,
                'last_test_message' => $cred->last_test_message,
                'updated_at' => $cred->updated_at?->format('Y-m-d H:i:s'),
            ],
        ]);
    }

    public function updateConfiguration(Request $request): JsonResponse
    {
        $environment = (string) ($request->input('environment') ?: 'sandbox');
        $environment = in_array($environment, ['sandbox', 'production'], true) ? $environment : 'sandbox';

        $validated = $request->validate([
            'merchant_code' => ['required', 'string', 'max:60'],
            'environment' => ['nullable', 'string', 'in:sandbox,production'],
            'midtrans_merchant_id' => ['nullable', 'string', 'max:100'],
            'client_key' => ['nullable', 'string', 'max:150'],
            'server_key' => ['nullable', 'string', 'max:255'],
            'signature_secret_key' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $merchantCode = $validated['merchant_code'];
        $cred = MidtransMerchantCredential::query()->firstOrCreate(
            ['merchant_code' => $merchantCode, 'environment' => $environment],
            ['merchant_name' => $merchantCode === 'udev-central' ? 'UDEV Central Billing' : $merchantCode, 'is_active' => true]
        );

        if (array_key_exists('midtrans_merchant_id', $validated)) {
            $cred->midtrans_merchant_id = $validated['midtrans_merchant_id'];
        }
        if (array_key_exists('client_key', $validated)) {
            $cred->client_key = $validated['client_key'];
        }
        if (array_key_exists('is_active', $validated)) {
            $cred->is_active = $validated['is_active'];
        }

        // Encrypt Server Key if provided
        if (! empty($validated['server_key'])) {
            $encrypted = $this->secretStore->encryptSecret(
                trim($validated['server_key']),
                $merchantCode,
                'server_key',
                'v1',
                $environment
            );
            $cred->encrypted_server_key = $encrypted['ciphertext'];
            $cred->server_key_nonce = $encrypted['nonce'];
            $cred->server_key_tag = $encrypted['tag'];
            $cred->key_version = 'v1';
        }

        // Encrypt Signature Secret Key if provided
        if (! empty($validated['signature_secret_key'])) {
            $encryptedSig = $this->secretStore->encryptSecret(
                trim($validated['signature_secret_key']),
                $merchantCode,
                'signature_key',
                'v1',
                $environment
            );
            $cred->encrypted_signature_key = $encryptedSig['ciphertext'];
            $cred->signature_key_nonce = $encryptedSig['nonce'];
            $cred->signature_key_tag = $encryptedSig['tag'];
        }

        $cred->save();

        $this->auditService->record(
            merchantCode: $merchantCode,
            action: 'configuration.saved',
            status: 'success',
            metadata: [
                'merchant_code' => $merchantCode,
                'environment' => $environment,
                'has_server_key' => ! empty($cred->encrypted_server_key),
                'has_client_key' => ! empty($cred->client_key),
                'has_signature_key' => ! empty($cred->encrypted_signature_key),
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Konfigurasi API Midtrans berhasil disimpan terenkripsi.',
            'data' => [
                'merchant_code' => $cred->merchant_code,
                'environment' => $cred->environment,
                'midtrans_merchant_id' => $cred->midtrans_merchant_id,
                'client_key' => $cred->client_key,
                'has_server_key' => ! empty($cred->encrypted_server_key),
                'has_signature_key' => ! empty($cred->encrypted_signature_key),
                'is_active' => (bool) $cred->is_active,
            ],
        ]);
    }

    public function deleteConfiguration(Request $request): JsonResponse
    {
        $merchantCode = (string) $request->query('merchant_code', '');
        $environment = (string) $request->query('environment', 'sandbox');
        $environment = in_array($environment, ['sandbox', 'production'], true) ? $environment : 'sandbox';

        $cred = MidtransMerchantCredential::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', $environment)
            ->firstOrFail();

        $cred->update([
            'encrypted_server_key' => null,
            'server_key_nonce' => null,
            'server_key_tag' => null,
            'encrypted_signature_key' => null,
            'signature_key_nonce' => null,
            'signature_key_tag' => null,
            'last_tested_at' => null,
            'last_test_status' => null,
            'last_test_message' => null,
        ]);

        $this->auditService->record(
            merchantCode: $merchantCode,
            action: 'configuration.deleted',
            status: 'success',
            metadata: ['environment' => $environment]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Kredensial Midtrans berhasil dihapus.',
        ]);
    }

    public function testConnection(Request $request): JsonResponse
    {
        $environment = (string) ($request->input('environment') ?: $request->query('environment', 'sandbox'));
        $environment = in_array($environment, ['sandbox', 'production'], true) ? $environment : 'sandbox';

        $validated = $request->validate([
            'merchant_code' => ['required', 'string'],
            'environment' => ['nullable', 'string', 'in:sandbox,production'],
        ]);

        $merchantCode = $validated['merchant_code'];
        $cred = MidtransMerchantCredential::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', $environment)
            ->firstOrFail();

        if (empty($cred->encrypted_server_key)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Server Key Midtrans belum dikonfigurasi untuk merchant ini pada environment '.ucfirst($environment).'.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $serverKey = $this->secretStore->decryptSecret(
                $cred->encrypted_server_key,
                $cred->server_key_nonce,
                $cred->server_key_tag,
                $cred->merchant_code,
                'server_key',
                $cred->key_version ?? 'v1',
                $environment
            );
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Gagal mendekripsi Server Key: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $result = $this->gatewayClient->testConnection($merchantCode, $serverKey, $environment);

        $cred->update([
            'last_tested_at' => now(),
            'last_test_status' => $result['status'],
            'last_test_response_code' => (string) $result['http_status'],
            'last_test_message' => $result['message'],
        ]);

        $this->auditService->record(
            merchantCode: $merchantCode,
            action: 'connection.tested',
            status: $result['status'],
            responseCode: (string) $result['http_status'],
            latencyMs: $result['latency_ms'] ?? null,
            metadata: [
                'environment' => $environment,
                'is_connected' => $result['is_connected'],
                'http_status' => $result['http_status'],
            ]
        );

        return response()->json($result);
    }

    public function createSandboxPayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'merchant_code' => ['required', 'string'],
            'order_id' => ['nullable', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:1000'],
            'payment_type' => ['nullable', 'string', 'in:qris,bank_transfer,echannel,gopay'],
            'bank' => ['nullable', 'string', 'in:bca,bni,bri,permata,mandiri'],
        ]);

        $merchantCode = $validated['merchant_code'];
        $cred = MidtransMerchantCredential::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', 'sandbox')
            ->firstOrFail();

        if (empty($cred->encrypted_server_key)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Server Key Midtrans belum dikonfigurasi.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $serverKey = $this->secretStore->decryptSecret(
                $cred->encrypted_server_key,
                $cred->server_key_nonce,
                $cred->server_key_tag,
                $cred->merchant_code,
                'server_key',
                $cred->key_version ?? 'v1'
            );
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Gagal mendekripsi Server Key.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $result = $this->gatewayClient->createSandboxCharge($merchantCode, $serverKey, $validated, 'sandbox');

        $this->auditService->record(
            merchantCode: $merchantCode,
            action: 'sandbox.payment_created',
            status: $result['status'] ?? 'success',
            metadata: [
                'order_id' => $result['data']['order_id'] ?? null,
                'gross_amount' => $validated['amount'],
                'payment_type' => $result['data']['payment_type'] ?? ($validated['payment_type'] ?? 'qris'),
                'bank' => $validated['bank'] ?? null,
                'transaction_id' => $result['data']['transaction_id'] ?? null,
                'va_numbers' => $result['data']['va_numbers'] ?? null,
                'permata_va_number' => $result['data']['permata_va_number'] ?? null,
                'bill_key' => $result['data']['bill_key'] ?? null,
                'biller_code' => $result['data']['biller_code'] ?? null,
            ]
        );

        return response()->json($result);
    }

    public function querySandboxPayment(Request $request, string $orderId): JsonResponse
    {
        $merchantCode = (string) $request->query('merchant_code', 'udev-central');
        $cred = MidtransMerchantCredential::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', 'sandbox')
            ->firstOrFail();

        if (empty($cred->encrypted_server_key)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Server Key Midtrans belum dikonfigurasi.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $serverKey = $this->secretStore->decryptSecret(
                $cred->encrypted_server_key,
                $cred->server_key_nonce,
                $cred->server_key_tag,
                $cred->merchant_code,
                'server_key',
                $cred->key_version ?? 'v1'
            );
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Gagal mendekripsi Server Key.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $result = $this->gatewayClient->querySandboxStatus($merchantCode, $serverKey, $orderId, 'sandbox');

        $this->auditService->record(
            merchantCode: $merchantCode,
            action: 'sandbox.payment_queried',
            status: $result['status'] ?? 'success',
            metadata: ['order_id' => $orderId, 'transaction_status' => $result['data']['transaction_status'] ?? null]
        );

        return response()->json($result);
    }

    public function sandboxTestTransactions(Request $request): JsonResponse
    {
        $merchantCode = (string) $request->query('merchant_code', '');

        $query = MidtransGatewayAudit::query()
            ->where('action', 'sandbox.payment_created');

        if ($merchantCode !== '') {
            $query->where('merchant_code', $merchantCode);
        }

        $perPage = max(5, min(100, (int) $request->query('per_page', 15)));
        $audits = $query->orderByDesc('id')->paginate($perPage);

        return response()->json($audits);
    }

    public function indexAudits(Request $request): JsonResponse
    {
        $merchantCode = (string) $request->query('merchant_code', '');
        $status = (string) $request->query('status', '');
        $search = trim((string) $request->query('search', ''));

        $query = MidtransGatewayAudit::query()->with('adminUser:id,name,username');

        if ($merchantCode !== '') {
            $query->where('merchant_code', $merchantCode);
        }
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', "%{$search}%")
                    ->orWhere('merchant_code', 'like', "%{$search}%");
            });
        }

        $perPage = max(5, min(100, (int) $request->query('per_page', 15)));
        $audits = $query->orderByDesc('id')->paginate($perPage);

        return response()->json($audits);
    }

    public function getEnvironment(Request $request): JsonResponse
    {
        $setting = $this->environmentService->getSetting();
        $readiness = $this->environmentService->evaluateProductionReadiness();

        $readinessData = array_merge($readiness, [
            'is_ready_for_production' => $readiness['is_ready'],
        ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'active_environment' => $setting->active_environment,
                'is_production' => $setting->isProduction(),
                'is_sandbox' => $setting->isSandbox(),
                'is_production_ready' => $readiness['is_ready'],
                'readiness' => $readinessData,
                'production_readiness' => $readinessData,
                'production_settings' => [
                    'activated_at' => $setting->production_activated_at?->format('Y-m-d H:i:s'),
                    'activated_by_name' => $setting->productionActivatedBy?->name,
                    'notes' => $setting->activation_notes,
                ],
                'production_activated_at' => $setting->production_activated_at?->format('Y-m-d H:i:s'),
                'production_activated_by' => $setting->productionActivatedBy?->name,
                'last_switched_at' => $setting->last_switched_at?->format('Y-m-d H:i:s'),
                'last_switched_by' => $setting->lastSwitchedBy?->name,
                'activation_notes' => $setting->activation_notes,
            ],
        ]);
    }

    public function activateProduction(Request $request): JsonResponse
    {
        $phrase = (string) ($request->input('confirmation_phrase') ?? $request->input('confirmation', ''));
        if ($phrase === '') {
            return response()->json([
                'status' => 'failed',
                'message' => 'The confirmation phrase field is required.',
                'errors' => [
                    'confirmation' => ['The confirmation field is required.'],
                    'confirmation_phrase' => ['The confirmation phrase field is required.'],
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $setting = $this->environmentService->activateProduction(
                adminUser: $request->user(),
                confirmationPhrase: $phrase,
                notes: $validated['notes'] ?? null,
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            $readiness = $this->environmentService->evaluateProductionReadiness();
            return response()->json([
                'status' => 'failed',
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
                'readiness' => array_merge($readiness, [
                    'is_ready_for_production' => $readiness['is_ready'],
                ]),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Environment Midtrans Production berhasil diaktifkan.',
            'data' => [
                'active_environment' => $setting->active_environment,
                'activated_by_id' => $setting->production_activated_by,
                'production_activated_at' => $setting->production_activated_at?->format('Y-m-d H:i:s'),
                'production_activated_by' => $setting->productionActivatedBy?->name,
                'last_switched_at' => $setting->last_switched_at?->format('Y-m-d H:i:s'),
            ],
        ]);
    }

    public function switchSandbox(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $setting = $this->environmentService->switchToSandbox(
            adminUser: $request->user(),
            notes: $validated['notes'] ?? null,
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Environment Midtrans berhasil dikembalikan ke Sandbox.',
            'data' => [
                'active_environment' => $setting->active_environment,
                'last_switched_at' => $setting->last_switched_at?->format('Y-m-d H:i:s'),
                'last_switched_by' => $setting->lastSwitchedBy?->name,
            ],
        ]);
    }

    public function dashboardSummary(Request $request): JsonResponse
    {
        $env = (string) $request->query('environment', '');
        $setting = $this->environmentService->getSetting();
        $targetEnv = in_array($env, ['sandbox', 'production'], true) ? $env : 'sandbox';

        $totalMerchants = MidtransMerchantCredential::query()->where('environment', $targetEnv)->count();
        $activeMerchants = MidtransMerchantCredential::query()->where('environment', $targetEnv)->where('is_active', true)->count();
        $configuredMerchants = MidtransMerchantCredential::query()->where('environment', $targetEnv)->whereNotNull('encrypted_server_key')->count();

        $totalMappings = ApplicationPaymentGateway::query()
            ->where('provider', 'midtrans')
            ->where('environment', $targetEnv)
            ->count();
        $activeMappings = ApplicationPaymentGateway::query()
            ->where('provider', 'midtrans')
            ->where('environment', $targetEnv)
            ->where('is_active', true)
            ->count();

        $billingAttemptsQuery = PaymentAttempt::query()
            ->whereRaw('LOWER(provider) = ?', ['midtrans'])
            ->where('gateway_environment', $targetEnv);

        $totalBillingTransactions = $billingAttemptsQuery->count();
        $successfulBillingTransactions = (clone $billingAttemptsQuery)->whereIn('status', ['succeeded', 'paid'])->count();

        $totalSandboxTests = MidtransGatewayAudit::query()
            ->where('action', 'sandbox.payment_created')
            ->count();

        $latestTest = MidtransMerchantCredential::query()
            ->where('environment', $targetEnv)
            ->whereNotNull('last_tested_at')
            ->orderByDesc('last_tested_at')
            ->first();

        $apiStatus = 'untested';
        if ($latestTest !== null) {
            $apiStatus = $latestTest->last_test_status === 'success' ? 'online' : 'error';
        }

        $readiness = $this->environmentService->evaluateProductionReadiness();

        return response()->json([
            'data' => [
                'active_environment' => $setting->active_environment,
                'environment' => $targetEnv,
                'is_production' => $setting->isProduction(),
                'is_sandbox' => $setting->isSandbox(),
                'is_production_ready' => $readiness['is_ready'],
                'total_merchants' => $totalMerchants,
                'active_merchants' => $activeMerchants,
                'configured_merchants' => $configuredMerchants,
                'total_mappings' => $totalMappings,
                'active_mappings' => $activeMappings,
                'total_billing_transactions' => $totalBillingTransactions,
                'successful_billing_transactions' => $successfulBillingTransactions,
                'total_sandbox_tests' => $totalSandboxTests,
                'latest_test' => $latestTest ? [
                    'merchant_code' => $latestTest->merchant_code,
                    'status' => $latestTest->last_test_status,
                    'message' => $latestTest->last_test_message,
                    'tested_at' => $latestTest->last_tested_at?->format('Y-m-d H:i:s'),
                ] : null,
                'api_status' => $apiStatus,
            ],
        ]);
    }

    public function indexFees(Request $request): JsonResponse
    {
        $merchantCode = trim((string) $request->query('merchant_code', 'udev-central'));
        if ($merchantCode === '') {
            $merchantCode = 'udev-central';
        }

        $this->feeService->ensureDefaultFeesForMerchant($merchantCode);

        $query = MidtransPaymentMethodFee::query()
            ->where('merchant_code', $merchantCode);

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('payment_method', 'like', "%{$search}%")
                    ->orWhere('payment_method_name', 'like', "%{$search}%");
            });
        }

        $feeType = $request->query('fee_type');
        if (! empty($feeType) && in_array($feeType, ['fixed', 'percent'], true)) {
            $query->where('fee_type', $feeType);
        }

        $status = $request->query('status');
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $perPage = (int) $request->query('per_page', 15);
        if ($perPage < 1) {
            $perPage = 15;
        }

        $fees = $query->orderBy('id')->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $fees->items(),
            'meta' => [
                'current_page' => $fees->currentPage(),
                'per_page' => $fees->perPage(),
                'total' => $fees->total(),
                'last_page' => $fees->lastPage(),
                'has_more' => $fees->hasMorePages(),
            ],
        ]);
    }

    public function storeFee(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'merchant_code' => ['nullable', 'string', 'max:60'],
            'payment_method' => ['required', 'string', 'max:50'],
            'payment_method_name' => ['required', 'string', 'max:100'],
            'fee_type' => ['required', 'string', 'in:fixed,percent'],
            'fee_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fixed_amount' => ['nullable', 'numeric', 'min:0'],
            'min_fee' => ['nullable', 'numeric', 'min:0'],
            'max_fee' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $merchantCode = $validated['merchant_code'] ?? 'udev-central';
        $paymentMethod = $validated['payment_method'];

        $exists = MidtransPaymentMethodFee::query()
            ->where('merchant_code', $merchantCode)
            ->where('payment_method', $paymentMethod)
            ->exists();

        if ($exists) {
            return response()->json([
                'status' => 'error',
                'message' => "Metode pembayaran '{$paymentMethod}' sudah dikonfigurasi untuk merchant '{$merchantCode}'.",
                'errors' => [
                    'payment_method' => ["Metode pembayaran '{$paymentMethod}' sudah ada."],
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $fee = MidtransPaymentMethodFee::query()->create([
            'merchant_code' => $merchantCode,
            'payment_method' => $paymentMethod,
            'payment_method_name' => $validated['payment_method_name'],
            'fee_type' => $validated['fee_type'],
            'fee_rate' => $validated['fee_rate'] ?? 0.0000,
            'fixed_amount' => $validated['fixed_amount'] ?? 0.00,
            'min_fee' => $validated['min_fee'] ?? null,
            'max_fee' => $validated['max_fee'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'notes' => $validated['notes'] ?? null,
        ]);

        $this->auditService->record(
            merchantCode: $merchantCode,
            action: 'fee.created',
            status: 'success',
            metadata: [
                'fee_id' => $fee->id,
                'payment_method' => $paymentMethod,
                'fee_type' => $fee->fee_type,
                'fee_rate' => $fee->fee_rate,
                'fixed_amount' => $fee->fixed_amount,
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Biaya metode pembayaran berhasil ditambahkan.',
            'data' => $fee,
        ], Response::HTTP_CREATED);
    }

    public function showFee(string $id): JsonResponse
    {
        $fee = MidtransPaymentMethodFee::query()->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $fee,
        ]);
    }

    public function updateFee(Request $request, string $id): JsonResponse
    {
        $fee = MidtransPaymentMethodFee::query()->findOrFail($id);

        $validated = $request->validate([
            'payment_method_name' => ['sometimes', 'required', 'string', 'max:100'],
            'fee_type' => ['sometimes', 'required', 'string', 'in:fixed,percent'],
            'fee_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fixed_amount' => ['nullable', 'numeric', 'min:0'],
            'min_fee' => ['nullable', 'numeric', 'min:0'],
            'max_fee' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $fee->update($validated);

        $this->auditService->record(
            merchantCode: $fee->merchant_code,
            action: 'fee.updated',
            status: 'success',
            metadata: array_merge(['fee_id' => $fee->id], $validated)
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Konfigurasi biaya metode pembayaran berhasil diperbarui.',
            'data' => $fee,
        ]);
    }

    public function toggleFeeStatus(Request $request, string $id): JsonResponse
    {
        $fee = MidtransPaymentMethodFee::query()->findOrFail($id);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $fee->update(['is_active' => $validated['is_active']]);

        $this->auditService->record(
            merchantCode: $fee->merchant_code,
            action: $validated['is_active'] ? 'fee.activated' : 'fee.deactivated',
            status: 'success',
            metadata: ['fee_id' => $fee->id, 'payment_method' => $fee->payment_method]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Status biaya metode pembayaran berhasil diperbarui.',
            'data' => $fee,
        ]);
    }

    public function destroyFee(string $id): JsonResponse
    {
        $fee = MidtransPaymentMethodFee::query()->findOrFail($id);
        $merchantCode = $fee->merchant_code;
        $paymentMethod = $fee->payment_method;

        $fee->delete();

        $this->auditService->record(
            merchantCode: $merchantCode,
            action: 'fee.deleted',
            status: 'success',
            metadata: ['fee_id' => $id, 'payment_method' => $paymentMethod]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Konfigurasi biaya metode pembayaran berhasil dihapus.',
        ]);
    }

    public function calculateFeePreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'merchant_code' => ['nullable', 'string'],
            'payment_method' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        $merchantCode = $validated['merchant_code'] ?? 'udev-central';
        $paymentMethod = $validated['payment_method'];
        $subtotal = (float) $validated['amount'];

        $result = $this->feeService->calculateFee($merchantCode, $paymentMethod, $subtotal);

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }
}
