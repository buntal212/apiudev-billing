<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApplicationPaymentGateway;
use App\Models\MidtransGatewayAudit;
use App\Models\MidtransMerchantCredential;
use App\Models\PaymentAttempt;
use App\Services\MidtransAuditService;
use App\Services\MidtransGatewayProvisioningClient;
use App\Services\MidtransSecretStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MidtransGatewayAdminController extends Controller
{
    public function __construct(
        private MidtransSecretStore $secretStore,
        private MidtransGatewayProvisioningClient $gatewayClient,
        private MidtransAuditService $auditService,
    ) {}

    public function indexMerchants(Request $request): JsonResponse
    {
        // Ensure default merchants exist
        MidtransMerchantCredential::query()->firstOrCreate(
            ['merchant_code' => 'udev-central', 'environment' => 'sandbox'],
            ['merchant_name' => 'UDEV Central Billing', 'is_active' => true]
        );
        MidtransMerchantCredential::query()->firstOrCreate(
            ['merchant_code' => 'larissama', 'environment' => 'sandbox'],
            ['merchant_name' => 'Larissama', 'is_active' => false]
        );

        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');

        $query = MidtransMerchantCredential::query()
            ->where('environment', 'sandbox');

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

        $mappings = ApplicationPaymentGateway::query()
            ->with('application:id,code,name')
            ->where('provider', 'midtrans')
            ->where('environment', 'sandbox')
            ->get()
            ->groupBy('gateway_merchant_code');

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
        $validated = $request->validate([
            'merchant_code' => [
                'required',
                'string',
                'max:60',
                'regex:/^[a-z0-9_-]+$/',
                'unique:midtrans_merchant_credentials,merchant_code',
            ],
            'merchant_name' => ['required', 'string', 'max:100'],
            'midtrans_merchant_id' => ['nullable', 'string', 'max:100'],
            'client_key' => ['nullable', 'string', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $merchant = MidtransMerchantCredential::create([
            'merchant_code' => $validated['merchant_code'],
            'merchant_name' => $validated['merchant_name'],
            'midtrans_merchant_id' => $validated['midtrans_merchant_id'] ?? null,
            'client_key' => $validated['client_key'] ?? null,
            'environment' => 'sandbox',
            'is_active' => $validated['is_active'] ?? true,
        ]);

        $this->auditService->record(
            merchantCode: $merchant->merchant_code,
            action: 'merchant.created',
            status: 'success',
            metadata: ['merchant_code' => $merchant->merchant_code, 'merchant_name' => $merchant->merchant_name]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Merchant Midtrans berhasil ditambahkan.',
            'data' => $merchant,
        ], Response::HTTP_CREATED);
    }

    public function showMerchant(string $merchantCode): JsonResponse
    {
        $merchant = MidtransMerchantCredential::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', 'sandbox')
            ->firstOrFail();

        return response()->json(['data' => $merchant]);
    }

    public function updateMerchant(Request $request, string $merchantCode): JsonResponse
    {
        $merchant = MidtransMerchantCredential::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', 'sandbox')
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
            metadata: $validated
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Data merchant Midtrans berhasil diperbarui.',
            'data' => $merchant,
        ]);
    }

    public function toggleMerchantStatus(Request $request, string $merchantCode): JsonResponse
    {
        $merchant = MidtransMerchantCredential::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', 'sandbox')
            ->firstOrFail();

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $merchant->update(['is_active' => $validated['is_active']]);

        $this->auditService->record(
            merchantCode: $merchant->merchant_code,
            action: $validated['is_active'] ? 'merchant.activated' : 'merchant.deactivated',
            status: 'success'
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Status merchant berhasil diperbarui.',
            'data' => $merchant,
        ]);
    }

    public function destroyMerchant(string $merchantCode): JsonResponse
    {
        $merchant = MidtransMerchantCredential::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', 'sandbox')
            ->firstOrFail();

        $mappingCount = ApplicationPaymentGateway::query()
            ->where('provider', 'midtrans')
            ->where('environment', 'sandbox')
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
            status: 'success'
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Merchant Midtrans berhasil dihapus.',
        ]);
    }

    public function showConfiguration(Request $request): JsonResponse
    {
        $merchantCode = (string) $request->query('merchant_code', 'udev-central');

        $cred = MidtransMerchantCredential::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', 'sandbox')
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
                    $cred->key_version ?? 'v1'
                );
                $len = strlen($decrypted);
                $maskedServerKey = $len <= 8 ? str_repeat('*', $len) : substr($decrypted, 0, 8).str_repeat('*', max(4, $len - 12)).substr($decrypted, -4);
            } catch (\Throwable) {
                $maskedServerKey = 'SB-Mid-server-********';
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
                    $cred->key_version ?? 'v1'
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
        $validated = $request->validate([
            'merchant_code' => ['required', 'string', 'max:60'],
            'midtrans_merchant_id' => ['nullable', 'string', 'max:100'],
            'client_key' => ['nullable', 'string', 'max:150'],
            'server_key' => ['nullable', 'string', 'max:255'],
            'signature_secret_key' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $merchantCode = $validated['merchant_code'];
        $cred = MidtransMerchantCredential::query()->firstOrCreate(
            ['merchant_code' => $merchantCode, 'environment' => 'sandbox'],
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
                'v1'
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
                'v1'
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
        $cred = MidtransMerchantCredential::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', 'sandbox')
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
            status: 'success'
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Kredensial Midtrans berhasil dihapus.',
        ]);
    }

    public function testConnection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'merchant_code' => ['required', 'string'],
        ]);

        $merchantCode = $validated['merchant_code'];
        $cred = MidtransMerchantCredential::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', 'sandbox')
            ->firstOrFail();

        if (empty($cred->encrypted_server_key)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Server Key Midtrans belum dikonfigurasi untuk merchant ini.',
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
                'message' => 'Gagal mendekripsi Server Key: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $result = $this->gatewayClient->testConnection($merchantCode, $serverKey, 'sandbox');

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
            metadata: ['is_connected' => $result['is_connected'], 'http_status' => $result['http_status']]
        );

        return response()->json($result);
    }

    public function createSandboxPayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'merchant_code' => ['required', 'string'],
            'order_id' => ['nullable', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:1000'],
            'payment_type' => ['nullable', 'string', 'in:qris,bank_transfer,gopay'],
            'bank' => ['nullable', 'string', 'in:bca,bni,bri,permata'],
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
                'payment_type' => $validated['payment_type'] ?? 'qris',
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

    public function dashboardSummary(Request $request): JsonResponse
    {
        $totalMerchants = MidtransMerchantCredential::query()->where('environment', 'sandbox')->count();
        $activeMerchants = MidtransMerchantCredential::query()->where('environment', 'sandbox')->where('is_active', true)->count();
        $configuredMerchants = MidtransMerchantCredential::query()->where('environment', 'sandbox')->whereNotNull('encrypted_server_key')->count();

        $totalMappings = ApplicationPaymentGateway::query()
            ->where('provider', 'midtrans')
            ->where('environment', 'sandbox')
            ->count();
        $activeMappings = ApplicationPaymentGateway::query()
            ->where('provider', 'midtrans')
            ->where('environment', 'sandbox')
            ->where('is_active', true)
            ->count();

        $billingAttemptsQuery = PaymentAttempt::query()
            ->whereRaw('LOWER(provider) = ?', ['midtrans'])
            ->where('gateway_environment', 'sandbox');

        $totalBillingTransactions = $billingAttemptsQuery->count();
        $successfulBillingTransactions = (clone $billingAttemptsQuery)->whereIn('status', ['succeeded', 'paid'])->count();

        $totalSandboxTests = MidtransGatewayAudit::query()
            ->where('action', 'sandbox.payment_created')
            ->count();

        $latestTest = MidtransMerchantCredential::query()
            ->whereNotNull('last_tested_at')
            ->orderByDesc('last_tested_at')
            ->first();

        $apiStatus = 'untested';
        if ($latestTest !== null) {
            $apiStatus = $latestTest->last_test_status === 'success' ? 'online' : 'error';
        }

        return response()->json([
            'data' => [
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
}
