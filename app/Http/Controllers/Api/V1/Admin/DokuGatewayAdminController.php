<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDokuConfigurationRequest;
use App\Models\DokuGatewayAudit;
use App\Models\DokuMerchantCredential;
use App\Services\DokuAuditService;
use App\Services\DokuGatewayProvisioningClient;
use App\Services\DokuSecretStore;
use App\Services\DokuSnapSignatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DokuGatewayAdminController extends Controller
{
    public function __construct(
        private DokuSecretStore $secretStore,
        private DokuSnapSignatureService $signatureService,
        private DokuGatewayProvisioningClient $gatewayClient,
        private DokuAuditService $auditService,
    ) {}

    public function indexMerchants(): JsonResponse
    {
        try {
            $existing = DokuMerchantCredential::query()
                ->where('environment', 'sandbox')
                ->get();
        } catch (\Throwable) {
            $existing = collect();
        }

        $configuredCodes = $existing->pluck('merchant_code')->all();

        $merchants = [
            [
                'merchant_code' => 'udev-central',
                'merchant_name' => 'UDEV Central Billing',
                'environment' => 'sandbox',
                'is_configured' => in_array('udev-central', $configuredCodes, true),
                'is_active' => $existing->firstWhere('merchant_code', 'udev-central')?->is_active ?? true,
            ],
            [
                'merchant_code' => 'larissama',
                'merchant_name' => 'Larissama',
                'environment' => 'sandbox',
                'is_configured' => in_array('larissama', $configuredCodes, true),
                'is_active' => $existing->firstWhere('merchant_code', 'larissama')?->is_active ?? false,
            ],
        ];

        // Tambahkan merchant lain yang ada di database
        foreach ($existing as $cred) {
            if ($cred->merchant_code !== 'udev-central' && $cred->merchant_code !== 'larissama') {
                $merchants[] = [
                    'merchant_code' => $cred->merchant_code,
                    'merchant_name' => $cred->merchant_name,
                    'environment' => 'sandbox',
                    'is_configured' => true,
                    'is_active' => $cred->is_active,
                ];
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => $merchants,
        ]);
    }

    public function showConfiguration(Request $request): JsonResponse
    {
        $merchantCode = (string) $request->query('merchant_code', 'udev-central');

        try {
            $cred = DokuMerchantCredential::query()
                ->where('merchant_code', $merchantCode)
                ->where('environment', 'sandbox')
                ->first();
        } catch (\Throwable) {
            $cred = null;
        }

        $cacheStatus = $this->gatewayClient->checkCacheStatus($merchantCode, 'sandbox');

        if ($cred === null) {
            return response()->json([
                'status' => 'success',
                'data' => [
                    'merchant_code' => $merchantCode,
                    'merchant_name' => $merchantCode === 'udev-central' ? 'UDEV Central Billing' : $merchantCode,
                    'client_id' => '',
                    'masked_client_id' => '',
                    'environment' => 'sandbox',
                    'is_configured' => false,
                    'is_active' => true,
                    'has_private_key' => false,
                    'public_key' => null,
                    'public_key_fingerprint' => null,
                    'last_token_tested_at' => null,
                    'last_token_test_status' => null,
                    'last_token_test_response_code' => null,
                    'last_token_test_message' => null,
                    'token_cache_active' => false,
                    'token_cache_remaining_seconds' => 0,
                ],
            ]);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'merchant_code' => $cred->merchant_code,
                'merchant_name' => $cred->merchant_name,
                'client_id' => '', // Jangan kirim plaintext clientId lengkap jika ingin masking murni
                'masked_client_id' => $cred->masked_client_id,
                'environment' => 'sandbox',
                'is_configured' => true,
                'is_active' => $cred->is_active,
                'has_private_key' => ! empty($cred->encrypted_private_key),
                'public_key' => $cred->public_key,
                'public_key_fingerprint' => $cred->public_key_fingerprint,
                'last_token_tested_at' => $cred->last_token_tested_at?->format('Y-m-d H:i:s'),
                'last_token_test_status' => $cred->last_token_test_status,
                'last_token_test_response_code' => $cred->last_token_test_response_code,
                'last_token_test_message' => $cred->last_token_test_message,
                'token_cache_active' => $cacheStatus['is_active'],
                'token_cache_remaining_seconds' => $cacheStatus['remaining_seconds'],
            ],
        ]);
    }

    public function updateConfiguration(StoreDokuConfigurationRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $merchantCode = $validated['merchant_code'];
        $privateKey = $validated['private_key'];

        // Ekstrak public key & fingerprint dari private key
        $publicKey = $this->signatureService->extractPublicKey($privateKey);
        $fingerprint = $publicKey ? $this->signatureService->publicKeyFingerprint($publicKey) : null;

        // Enkripsi private key dengan AES-256-GCM
        $encrypted = $this->secretStore->encryptPrivateKey($privateKey, $merchantCode, 'v1');

        $cred = DokuMerchantCredential::updateOrCreate(
            [
                'merchant_code' => $merchantCode,
                'environment' => 'sandbox',
            ],
            [
                'merchant_name' => $validated['merchant_name'],
                'client_id' => $validated['client_id'],
                'is_active' => $validated['is_active'] ?? true,
                'encrypted_private_key' => $encrypted['ciphertext'],
                'key_nonce' => $encrypted['nonce'],
                'key_tag' => $encrypted['tag'],
                'key_version' => $encrypted['key_version'],
                'public_key' => $publicKey,
                'public_key_fingerprint' => $fingerprint,
            ]
        );

        $this->auditService->record(
            merchantCode: $merchantCode,
            action: 'credentials.saved',
            status: 'success',
            responseCode: '200',
            metadata: [
                'merchant_name' => $cred->merchant_name,
                'client_id_masked' => $cred->masked_client_id,
                'public_key_fingerprint' => $fingerprint,
            ],
            request: $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Kredensial DOKU Sandbox berhasil disimpan secara terenkripsi.',
            'data' => [
                'merchant_code' => $cred->merchant_code,
                'merchant_name' => $cred->merchant_name,
                'masked_client_id' => $cred->masked_client_id,
                'environment' => 'sandbox',
                'is_configured' => true,
                'is_active' => $cred->is_active,
                'has_private_key' => true,
                'public_key' => $cred->public_key,
                'public_key_fingerprint' => $cred->public_key_fingerprint,
            ],
        ]);
    }

    public function testB2BToken(Request $request): JsonResponse
    {
        $request->validate([
            'merchant_code' => ['required', 'string', 'max:60'],
            'force_refresh' => ['nullable', 'boolean'],
        ]);

        $merchantCode = (string) $request->input('merchant_code');
        $forceRefresh = (bool) $request->input('force_refresh', false);

        $cred = DokuMerchantCredential::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', 'sandbox')
            ->first();

        if ($cred === null || empty($cred->encrypted_private_key)) {
            return response()->json([
                'status' => 'failed',
                'message' => "Kredensial DOKU untuk merchant {$merchantCode} belum dikonfigurasi.",
            ], 422);
        }

        try {
            $privateKey = $this->secretStore->decryptPayload(
                $cred->encrypted_private_key,
                $cred->key_nonce,
                $cred->key_tag,
                $cred->merchant_code,
                $cred->key_version
            );
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Gagal mendekripsi RSA private key merchant: ' . $e->getMessage(),
            ], 422);
        }

        $result = $this->gatewayClient->testB2bToken(
            merchantCode: $merchantCode,
            clientId: $cred->client_id,
            privateKey: $privateKey,
            environment: 'sandbox',
            forceRefresh: $forceRefresh
        );

        // Update riwayat test terakhir pada tabel credential
        $cred->update([
            'last_token_tested_at' => now(),
            'last_token_test_status' => $result['status'] ?? 'failed',
            'last_token_test_response_code' => $result['response_code'] ?? null,
            'last_token_test_message' => $result['response_message'] ?? null,
        ]);

        $this->auditService->record(
            merchantCode: $merchantCode,
            action: 'token.tested',
            status: $result['status'] ?? 'failed',
            responseCode: $result['response_code'] ?? null,
            latencyMs: $result['latency_ms'] ?? 0,
            metadata: [
                'is_cached' => $result['is_cached'] ?? false,
                'expires_in' => $result['expires_in'] ?? 0,
                'buffer_seconds' => $result['buffer_seconds'] ?? 60,
                'cache_ttl_seconds' => $result['cache_ttl_seconds'] ?? 0,
                'masked_token' => $result['masked_token'] ?? '',
                'target_gateway' => 'doku-payment',
                'target_endpoint' => $result['target_endpoint'] ?? 'https://api-sandbox.doku.com/authorization/v1/access-token/b2b',
            ],
            request: $request
        );

        return response()->json([
            'status' => $result['status'],
            'data' => $result,
        ], $result['status'] === 'success' ? 200 : 400);
    }

    public function indexAudits(Request $request): JsonResponse
    {
        $query = DokuGatewayAudit::query()
            ->with('adminUser:id,name,username')
            ->latest('created_at');

        if ($request->filled('merchant_code')) {
            $query->where('merchant_code', $request->query('merchant_code'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('search')) {
            $search = '%' . $request->query('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', $search)
                    ->orWhere('response_code', 'like', $search)
                    ->orWhere('merchant_code', 'like', $search);
            });
        }

        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
        $audits = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $audits->items(),
            'meta' => [
                'current_page' => $audits->currentPage(),
                'last_page' => $audits->lastPage(),
                'per_page' => $audits->perPage(),
                'total' => $audits->total(),
            ],
        ]);
    }
}
