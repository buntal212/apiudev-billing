<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDokuConfigurationRequest;
use App\Models\DokuGatewayAudit;
use App\Models\DokuMerchantCredential;
use App\Models\DokuMerchantKeyBackup;
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
        $existing = DokuMerchantCredential::query()
            ->where('environment', 'sandbox')
            ->get();

        $backupMerchants = DokuMerchantKeyBackup::query()
            ->where('environment', 'sandbox')
            ->distinct()
            ->pluck('merchant_code')
            ->all();

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

        // Tambahkan merchant lain yang ada di database atau memiliki cadangan kunci
        $allCodes = array_unique(array_merge($configuredCodes, $backupMerchants));
        foreach ($allCodes as $code) {
            if ($code !== 'udev-central' && $code !== 'larissama') {
                $cred = $existing->firstWhere('merchant_code', $code);
                $merchants[] = [
                    'merchant_code' => $code,
                    'merchant_name' => $cred?->merchant_name ?? $code,
                    'environment' => 'sandbox',
                    'is_configured' => $cred !== null,
                    'is_active' => $cred?->is_active ?? false,
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

        $cred = DokuMerchantCredential::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', 'sandbox')
            ->first();

        $cacheStatus = $this->gatewayClient->checkCacheStatus($merchantCode, 'sandbox');
        $hasRecoveryBackup = DokuMerchantKeyBackup::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', 'sandbox')
            ->exists();

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
                    'has_recovery_backup' => $hasRecoveryBackup,
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
                'has_recovery_backup' => $hasRecoveryBackup,
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

    public function deleteConfiguration(Request $request): JsonResponse
    {
        $merchantCode = (string) ($request->input('merchant_code') ?: $request->query('merchant_code', 'udev-central'));

        $cred = DokuMerchantCredential::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', 'sandbox')
            ->first();

        $hasRecoveryBackup = DokuMerchantKeyBackup::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', 'sandbox')
            ->exists();

        if ($cred === null) {
            return response()->json([
                'status' => 'failed',
                'message' => "Konfigurasi merchant {$merchantCode} tidak ditemukan atau sudah dalam status Belum Dikonfigurasi.",
                'data' => [
                    'merchant_code' => $merchantCode,
                    'is_configured' => false,
                    'has_private_key' => false,
                    'has_recovery_backup' => $hasRecoveryBackup,
                ],
            ], 404);
        }

        $merchantName = $cred->merchant_name;
        $maskedClientId = $cred->masked_client_id;
        $deletedFingerprint = $cred->public_key_fingerprint;

        $cred->delete();

        \Illuminate\Support\Facades\Cache::forget("doku_snap_b2b_token:{$merchantCode}:sandbox");

        $this->auditService->record(
            merchantCode: $merchantCode,
            action: 'configuration.deleted',
            status: 'success',
            responseCode: '200',
            metadata: [
                'merchant_name' => $merchantName,
                'client_id_masked' => $maskedClientId,
                'deleted_fingerprint' => $deletedFingerprint,
                'has_recovery_backup' => $hasRecoveryBackup,
            ],
            request: $request
        );

        return response()->json([
            'status' => 'success',
            'message' => "Konfigurasi kredensial aktif DOKU Sandbox untuk merchant {$merchantCode} berhasil dihapus.",
            'data' => [
                'merchant_code' => $merchantCode,
                'merchant_name' => $merchantName,
                'environment' => 'sandbox',
                'is_configured' => false,
                'has_private_key' => false,
                'has_recovery_backup' => $hasRecoveryBackup,
            ],
        ]);
    }

    public function generateRsaKeyPair(Request $request): JsonResponse
    {
        $request->validate([
            'merchant_code' => ['required', 'string', 'max:60'],
            'confirm_rotation' => ['nullable', 'boolean'],
        ]);

        $merchantCode = (string) $request->input('merchant_code');
        $confirmRotation = (bool) $request->input('confirm_rotation', false);

        $cred = DokuMerchantCredential::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', 'sandbox')
            ->first();

        $hasExistingKey = $cred !== null && ! empty($cred->encrypted_private_key);

        if ($hasExistingKey && ! $confirmRotation) {
            return response()->json([
                'status' => 'confirmation_required',
                'message' => "Kredensial RSA sudah terdaftar untuk merchant {$merchantCode}. Konfirmasi rotasi diperlukan sebelum mengganti pasangan kunci aktif.",
                'data' => [
                    'merchant_code' => $merchantCode,
                    'current_fingerprint' => $cred->public_key_fingerprint,
                    'requires_confirmation' => true,
                    'has_recovery_backup' => DokuMerchantKeyBackup::query()
                        ->where('merchant_code', $merchantCode)
                        ->where('environment', 'sandbox')
                        ->exists(),
                ],
            ], 409);
        }

        // Jika rotasi dikonfirmasi, cadangkan kunci lama ke tabel backup
        if ($hasExistingKey && $confirmRotation) {
            DokuMerchantKeyBackup::create([
                'merchant_code' => $cred->merchant_code,
                'environment' => 'sandbox',
                'encrypted_private_key' => $cred->encrypted_private_key,
                'key_nonce' => $cred->key_nonce,
                'key_tag' => $cred->key_tag,
                'key_version' => $cred->key_version,
                'public_key' => (string) $cred->public_key,
                'public_key_fingerprint' => (string) $cred->public_key_fingerprint,
                'rotated_at' => now(),
                'admin_user_id' => $request->user()?->id,
                'reason' => 'rotation',
            ]);
        }

        // Hasilkan RSA 2048-bit key pair baru
        $pair = $this->signatureService->generateRsaKeyPair(2048);

        // Enkripsi private key dengan AES-256-GCM
        $encrypted = $this->secretStore->encryptPrivateKey($pair['private_key'], $merchantCode, 'v1');

        $merchantName = $cred?->merchant_name ?: ($merchantCode === 'udev-central' ? 'UDEV Central Billing' : $merchantCode);
        $clientId = $cred?->client_id ?: '';

        $cred = DokuMerchantCredential::updateOrCreate(
            [
                'merchant_code' => $merchantCode,
                'environment' => 'sandbox',
            ],
            [
                'merchant_name' => $merchantName,
                'client_id' => $clientId,
                'is_active' => $cred?->is_active ?? true,
                'encrypted_private_key' => $encrypted['ciphertext'],
                'key_nonce' => $encrypted['nonce'],
                'key_tag' => $encrypted['tag'],
                'key_version' => $encrypted['key_version'],
                'public_key' => $pair['public_key'],
                'public_key_fingerprint' => $pair['fingerprint'],
            ]
        );

        $action = $hasExistingKey ? 'key.rotated' : 'key.generated';
        $this->auditService->record(
            merchantCode: $merchantCode,
            action: $action,
            status: 'success',
            responseCode: '200',
            metadata: [
                'merchant_name' => $cred->merchant_name,
                'public_key_fingerprint' => $pair['fingerprint'],
                'key_bits' => 2048,
                'is_rotation' => $hasExistingKey,
            ],
            request: $request
        );

        \Illuminate\Support\Facades\Cache::forget("doku_snap_b2b_token:{$merchantCode}:sandbox");

        return response()->json([
            'status' => 'success',
            'message' => 'Pasangan RSA Key Pair 2048-bit berhasil dibuat dan disimpan terenkripsi.',
            'data' => [
                'merchant_code' => $cred->merchant_code,
                'merchant_name' => $cred->merchant_name,
                'environment' => 'sandbox',
                'is_configured' => true,
                'has_private_key' => true,
                'public_key' => $cred->public_key,
                'public_key_fingerprint' => $cred->public_key_fingerprint,
                'is_rotated' => $hasExistingKey,
                'has_recovery_backup' => DokuMerchantKeyBackup::query()
                    ->where('merchant_code', $merchantCode)
                    ->where('environment', 'sandbox')
                    ->exists(),
            ],
        ]);
    }

    public function recoverPreviousKey(Request $request): JsonResponse
    {
        $request->validate([
            'merchant_code' => ['required', 'string', 'max:60'],
        ]);

        $merchantCode = (string) $request->input('merchant_code');

        $backup = DokuMerchantKeyBackup::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', 'sandbox')
            ->latest('rotated_at')
            ->first();

        if ($backup === null) {
            return response()->json([
                'status' => 'failed',
                'message' => "Tidak ditemukan cadangan kunci RSA sebelumnya untuk merchant {$merchantCode}.",
            ], 422);
        }

        $cred = DokuMerchantCredential::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', 'sandbox')
            ->first();

        if ($cred === null) {
            $cred = new DokuMerchantCredential([
                'merchant_code' => $merchantCode,
                'merchant_name' => $merchantCode === 'udev-central' ? 'UDEV Central Billing' : $merchantCode,
                'client_id' => '',
                'environment' => 'sandbox',
                'is_active' => true,
            ]);
        } elseif (! empty($cred->encrypted_private_key)) {
            // Cadangkan kunci saat ini sebagai arsip pra-pemulihan agar riwayat tidak hilang
            DokuMerchantKeyBackup::create([
                'merchant_code' => $cred->merchant_code,
                'environment' => 'sandbox',
                'encrypted_private_key' => $cred->encrypted_private_key,
                'key_nonce' => $cred->key_nonce,
                'key_tag' => $cred->key_tag,
                'key_version' => $cred->key_version,
                'public_key' => (string) $cred->public_key,
                'public_key_fingerprint' => (string) $cred->public_key_fingerprint,
                'rotated_at' => now(),
                'admin_user_id' => $request->user()?->id,
                'reason' => 'pre_recovery_archive',
            ]);
        }

        // Pulihkan kunci dari backup
        $cred->fill([
            'encrypted_private_key' => $backup->encrypted_private_key,
            'key_nonce' => $backup->key_nonce,
            'key_tag' => $backup->key_tag,
            'key_version' => $backup->key_version,
            'public_key' => $backup->public_key,
            'public_key_fingerprint' => $backup->public_key_fingerprint,
        ]);
        $cred->save();

        // Hapus cadangan yang baru saja dipulihkan agar tumpukan rollback rapi
        $backup->delete();

        $this->auditService->record(
            merchantCode: $merchantCode,
            action: 'key.recovered',
            status: 'success',
            responseCode: '200',
            metadata: [
                'restored_fingerprint' => $cred->public_key_fingerprint,
            ],
            request: $request
        );

        \Illuminate\Support\Facades\Cache::forget("doku_snap_b2b_token:{$merchantCode}:sandbox");

        return response()->json([
            'status' => 'success',
            'message' => 'Pasangan kunci RSA sebelumnya berhasil dipulihkan.',
            'data' => [
                'merchant_code' => $cred->merchant_code,
                'merchant_name' => $cred->merchant_name,
                'environment' => 'sandbox',
                'is_configured' => true,
                'has_private_key' => true,
                'public_key' => $cred->public_key,
                'public_key_fingerprint' => $cred->public_key_fingerprint,
                'has_recovery_backup' => DokuMerchantKeyBackup::query()
                    ->where('merchant_code', $merchantCode)
                    ->where('environment', 'sandbox')
                    ->exists(),
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
