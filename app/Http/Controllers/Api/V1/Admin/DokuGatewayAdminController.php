<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDokuConfigurationRequest;
use App\Models\ApplicationPaymentGateway;
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

    public function indexMerchants(Request $request): JsonResponse
    {
        // Pastikan merchant default udev-central dan larissama tersedia
        DokuMerchantCredential::query()->firstOrCreate(
            ['merchant_code' => 'udev-central', 'environment' => 'sandbox'],
            ['merchant_name' => 'UDEV Central Billing', 'is_active' => true]
        );
        DokuMerchantCredential::query()->firstOrCreate(
            ['merchant_code' => 'larissama', 'environment' => 'sandbox'],
            ['merchant_name' => 'Larissama', 'is_active' => false]
        );

        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');

        $query = DokuMerchantCredential::query()
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
            $query->whereNotNull('client_id')->whereNotNull('encrypted_private_key');
        } elseif ($status === 'unconfigured') {
            $query->where(function ($q) {
                $q->whereNull('client_id')->orWhereNull('encrypted_private_key');
            });
        }

        $merchants = $query->orderBy('id')->get();

        $mappings = ApplicationPaymentGateway::query()
            ->with('application:id,code,name')
            ->where('provider', 'doku')
            ->where('environment', 'sandbox')
            ->get()
            ->groupBy('gateway_merchant_code');

        $backupMerchantCodes = DokuMerchantKeyBackup::query()
            ->where('environment', 'sandbox')
            ->pluck('merchant_code')
            ->all();

        $data = $merchants->map(function (DokuMerchantCredential $cred) use ($mappings, $backupMerchantCodes) {
            $hasClientId = ! empty($cred->client_id);
            $hasPrivateKey = ! empty($cred->encrypted_private_key);
            $isActive = (bool) $cred->is_active;
            $isB2bReady = $hasClientId && $hasPrivateKey && $isActive;
            $isConfigured = $hasClientId && $hasPrivateKey;
            $merchantMappings = $mappings->get($cred->merchant_code, collect());

            return [
                'id' => $cred->id,
                'merchant_code' => $cred->merchant_code,
                'merchant_name' => $cred->merchant_name,
                'client_id' => (string) ($cred->client_id ?? ''),
                'masked_client_id' => $cred->masked_client_id,
                'environment' => $cred->environment,
                'is_configured' => $isConfigured,
                'is_b2b_ready' => $isB2bReady,
                'is_active' => $isActive,
                'has_private_key' => $hasPrivateKey,
                'has_client_id' => $hasClientId,
                'public_key_fingerprint' => $cred->public_key_fingerprint,
                'last_token_test_status' => $cred->last_token_test_status,
                'last_token_tested_at' => $cred->last_token_tested_at?->format('Y-m-d H:i:s'),
                'has_recovery_backup' => in_array($cred->merchant_code, $backupMerchantCodes, true),
                'mapped_applications_count' => $merchantMappings->count(),
                'mapped_applications' => $merchantMappings->map(fn ($m) => [
                    'id' => $m->id,
                    'application_id' => $m->application_id,
                    'application_name' => $m->application?->name,
                    'application_code' => $m->application?->code,
                    'is_active' => (bool) $m->is_active,
                ])->values()->all(),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    public function storeMerchant(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'merchant_code' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9_-]+$/', 'unique:doku_merchant_credentials,merchant_code'],
            'merchant_name' => ['required', 'string', 'min:2', 'max:100'],
            'client_id' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $merchant = DokuMerchantCredential::query()->create([
            'merchant_code' => $validated['merchant_code'],
            'merchant_name' => $validated['merchant_name'],
            'client_id' => $validated['client_id'] ?? null,
            'environment' => 'sandbox',
            'is_active' => $validated['is_active'] ?? true,
        ]);

        $this->auditService->record(
            merchantCode: $merchant->merchant_code,
            action: 'merchant.created',
            status: 'success',
            responseCode: '201',
            metadata: [
                'merchant_name' => $merchant->merchant_name,
                'is_active' => (bool) $merchant->is_active,
            ],
            request: $request
        );

        return response()->json([
            'status' => 'success',
            'message' => "Merchant DOKU {$merchant->merchant_name} ({$merchant->merchant_code}) berhasil ditambahkan.",
            'data' => [
                'id' => $merchant->id,
                'merchant_code' => $merchant->merchant_code,
                'merchant_name' => $merchant->merchant_name,
                'masked_client_id' => $merchant->masked_client_id,
                'environment' => 'sandbox',
                'is_configured' => false,
                'is_active' => (bool) $merchant->is_active,
                'mapped_applications_count' => 0,
                'mapped_applications' => [],
            ],
        ], 201);
    }

    public function showMerchant(string $merchantCode): JsonResponse
    {
        $merchant = DokuMerchantCredential::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', 'sandbox')
            ->firstOrFail();

        $isConfigured = ! empty($merchant->client_id) && ! empty($merchant->encrypted_private_key);

        $mappings = ApplicationPaymentGateway::query()
            ->with('application:id,code,name')
            ->where('provider', 'doku')
            ->where('environment', 'sandbox')
            ->where('gateway_merchant_code', $merchantCode)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $merchant->id,
                'merchant_code' => $merchant->merchant_code,
                'merchant_name' => $merchant->merchant_name,
                'masked_client_id' => $merchant->masked_client_id,
                'environment' => 'sandbox',
                'is_configured' => $isConfigured,
                'is_active' => (bool) $merchant->is_active,
                'public_key_fingerprint' => $merchant->public_key_fingerprint,
                'last_token_test_status' => $merchant->last_token_test_status,
                'last_token_tested_at' => $merchant->last_token_tested_at?->format('Y-m-d H:i:s'),
                'mapped_applications_count' => $mappings->count(),
                'mapped_applications' => $mappings->map(fn ($m) => [
                    'id' => $m->id,
                    'application_id' => $m->application_id,
                    'application_name' => $m->application?->name,
                    'application_code' => $m->application?->code,
                    'is_active' => (bool) $m->is_active,
                ])->values()->all(),
            ],
        ]);
    }

    public function updateMerchant(string $merchantCode, Request $request): JsonResponse
    {
        $merchant = DokuMerchantCredential::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', 'sandbox')
            ->firstOrFail();

        $validated = $request->validate([
            'merchant_name' => ['required', 'string', 'min:2', 'max:100'],
            'client_id' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $updateData = [
            'merchant_name' => $validated['merchant_name'],
        ];

        if (array_key_exists('client_id', $validated)) {
            $incomingClientId = trim((string) $validated['client_id']);
            if (! str_contains($incomingClientId, '****')) {
                $updateData['client_id'] = $incomingClientId !== '' ? $incomingClientId : null;
            }
        }

        if (array_key_exists('is_active', $validated)) {
            $updateData['is_active'] = (bool) $validated['is_active'];
        }

        $merchant->update($updateData);

        $hasClientId = ! empty($merchant->client_id);
        $hasPrivateKey = ! empty($merchant->encrypted_private_key);
        $isActive = (bool) $merchant->is_active;
        $isB2bReady = $hasClientId && $hasPrivateKey && $isActive;
        $isConfigured = $hasClientId && $hasPrivateKey;

        $this->auditService->record(
            merchantCode: $merchant->merchant_code,
            action: 'merchant.updated',
            status: 'success',
            responseCode: '200',
            metadata: [
                'merchant_name' => $merchant->merchant_name,
                'is_active' => $isActive,
                'has_client_id' => $hasClientId,
                'has_private_key' => $hasPrivateKey,
                'is_b2b_ready' => $isB2bReady,
            ],
            request: $request
        );

        return response()->json([
            'status' => 'success',
            'message' => "Data merchant DOKU {$merchant->merchant_name} berhasil diperbarui.",
            'data' => [
                'id' => $merchant->id,
                'merchant_code' => $merchant->merchant_code,
                'merchant_name' => $merchant->merchant_name,
                'client_id' => (string) ($merchant->client_id ?? ''),
                'masked_client_id' => $merchant->masked_client_id,
                'environment' => 'sandbox',
                'is_configured' => $isConfigured,
                'is_b2b_ready' => $isB2bReady,
                'is_active' => $isActive,
                'has_private_key' => $hasPrivateKey,
                'has_client_id' => $hasClientId,
            ],
        ]);
    }

    public function toggleMerchantStatus(string $merchantCode, Request $request): JsonResponse
    {
        $merchant = DokuMerchantCredential::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', 'sandbox')
            ->firstOrFail();

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $merchant->update(['is_active' => $validated['is_active']]);

        $statusLabel = $merchant->is_active ? 'diaktifkan' : 'dinonaktifkan';

        $this->auditService->record(
            merchantCode: $merchant->merchant_code,
            action: 'merchant.status_toggled',
            status: 'success',
            responseCode: '200',
            metadata: [
                'is_active' => (bool) $merchant->is_active,
            ],
            request: $request
        );

        return response()->json([
            'status' => 'success',
            'message' => "Merchant DOKU {$merchant->merchant_name} berhasil {$statusLabel}.",
            'data' => [
                'merchant_code' => $merchant->merchant_code,
                'is_active' => (bool) $merchant->is_active,
            ],
        ]);
    }

    public function destroyMerchant(string $merchantCode, Request $request): JsonResponse
    {
        if ($merchantCode === 'udev-central') {
            return response()->json([
                'status' => 'failed',
                'message' => 'Merchant default udev-central tidak dapat dihapus.',
            ], 422);
        }

        $merchant = DokuMerchantCredential::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', 'sandbox')
            ->firstOrFail();

        $isMapped = ApplicationPaymentGateway::query()
            ->where('provider', 'doku')
            ->where('gateway_merchant_code', $merchantCode)
            ->exists();

        if ($isMapped) {
            return response()->json([
                'status' => 'failed',
                'message' => "Merchant {$merchant->merchant_name} ({$merchantCode}) tidak dapat dihapus karena masih terhubung dengan mapping aplikasi. Hapus mapping aplikasi terlebih dahulu.",
            ], 422);
        }

        $merchantName = $merchant->merchant_name;

        \Illuminate\Support\Facades\Cache::forget("doku_snap_b2b_token:{$merchantCode}:sandbox");

        DokuMerchantKeyBackup::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', 'sandbox')
            ->delete();

        $merchant->delete();

        $this->auditService->record(
            merchantCode: $merchantCode,
            action: 'merchant.deleted',
            status: 'success',
            responseCode: '200',
            metadata: [
                'merchant_name' => $merchantName,
            ],
            request: $request
        );

        return response()->json([
            'status' => 'success',
            'message' => "Merchant DOKU {$merchantName} ({$merchantCode}) berhasil dihapus.",
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
                    'is_b2b_ready' => false,
                    'is_active' => true,
                    'has_private_key' => false,
                    'has_client_id' => false,
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

        $hasPrivateKey = ! empty($cred->encrypted_private_key);
        $hasClientId = ! empty($cred->client_id);
        $isActive = (bool) $cred->is_active;
        $isB2bReady = $hasClientId && $hasPrivateKey && $isActive;
        $isConfigured = $hasPrivateKey || $hasClientId;

        return response()->json([
            'status' => 'success',
            'data' => [
                'merchant_code' => $cred->merchant_code,
                'merchant_name' => $cred->merchant_name,
                'client_id' => (string) ($cred->client_id ?? ''),
                'masked_client_id' => $cred->masked_client_id,
                'environment' => 'sandbox',
                'is_configured' => $isConfigured,
                'is_b2b_ready' => $isB2bReady,
                'is_active' => $isActive,
                'has_private_key' => $hasPrivateKey,
                'has_client_id' => $hasClientId,
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
        $privateKey = trim((string) ($validated['private_key'] ?? ''));

        $cred = DokuMerchantCredential::query()
            ->where('merchant_code', $merchantCode)
            ->where('environment', 'sandbox')
            ->first();

        $updateData = [
            'merchant_name' => $validated['merchant_name'],
            'client_id' => $validated['client_id'],
            'is_active' => $validated['is_active'] ?? ($cred?->is_active ?? true),
        ];

        $isNewKeyProvided = ! empty($privateKey);

        // Jika private key baru diinputkan secara manual, enkripsi dan perbarui pasangan kunci
        if ($isNewKeyProvided) {
            $publicKey = $this->signatureService->extractPublicKey($privateKey);
            $fingerprint = $publicKey ? $this->signatureService->publicKeyFingerprint($publicKey) : null;
            $encrypted = $this->secretStore->encryptPrivateKey($privateKey, $merchantCode, 'v1');

            $updateData['encrypted_private_key'] = $encrypted['ciphertext'];
            $updateData['key_nonce'] = $encrypted['nonce'];
            $updateData['key_tag'] = $encrypted['tag'];
            $updateData['key_version'] = $encrypted['key_version'];
            $updateData['public_key'] = $publicKey;
            $updateData['public_key_fingerprint'] = $fingerprint;
        }

        $cred = DokuMerchantCredential::updateOrCreate(
            [
                'merchant_code' => $merchantCode,
                'environment' => 'sandbox',
            ],
            $updateData
        );

        $hasPrivateKey = ! empty($cred->encrypted_private_key);
        $hasClientId = ! empty($cred->client_id);
        $isActive = (bool) $cred->is_active;
        $isB2bReady = $hasClientId && $hasPrivateKey && $isActive;
        $isConfigured = $hasPrivateKey || $hasClientId;

        $this->auditService->record(
            merchantCode: $merchantCode,
            action: 'credentials.saved',
            status: 'success',
            responseCode: '200',
            metadata: [
                'merchant_name' => $cred->merchant_name,
                'client_id_masked' => $cred->masked_client_id,
                'public_key_fingerprint' => $cred->public_key_fingerprint,
                'has_private_key' => $hasPrivateKey,
                'is_b2b_ready' => $isB2bReady,
                'key_reused' => ! $isNewKeyProvided && $hasPrivateKey,
            ],
            request: $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Kredensial DOKU Sandbox berhasil disimpan.',
            'data' => [
                'merchant_code' => $cred->merchant_code,
                'merchant_name' => $cred->merchant_name,
                'client_id' => (string) $cred->client_id,
                'masked_client_id' => $cred->masked_client_id,
                'environment' => 'sandbox',
                'is_configured' => $isConfigured,
                'is_b2b_ready' => $isB2bReady,
                'is_active' => $isActive,
                'has_private_key' => $hasPrivateKey,
                'has_client_id' => $hasClientId,
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

        $isConfigured = $cred !== null && (! empty($cred->client_id) || ! empty($cred->encrypted_private_key));

        if (! $isConfigured) {
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

        // Reset kredensial aktif tanpa menghapus merchant dari database
        $cred->update([
            'client_id' => null,
            'encrypted_private_key' => null,
            'key_nonce' => null,
            'key_tag' => null,
            'public_key' => null,
            'public_key_fingerprint' => null,
        ]);

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

        $hasClientId = ! empty($cred->client_id);
        $hasPrivateKey = ! empty($cred->encrypted_private_key);
        $isActive = (bool) $cred->is_active;
        $isB2bReady = $hasClientId && $hasPrivateKey && $isActive;
        $isConfigured = $hasPrivateKey || $hasClientId;

        return response()->json([
            'status' => 'success',
            'message' => 'Pasangan RSA Key Pair 2048-bit berhasil dibuat dan disimpan terenkripsi.',
            'data' => [
                'merchant_code' => $cred->merchant_code,
                'merchant_name' => $cred->merchant_name,
                'client_id' => (string) ($cred->client_id ?? ''),
                'masked_client_id' => $cred->masked_client_id,
                'environment' => 'sandbox',
                'is_configured' => $isConfigured,
                'is_b2b_ready' => $isB2bReady,
                'is_active' => $isActive,
                'has_private_key' => $hasPrivateKey,
                'has_client_id' => $hasClientId,
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

        $hasClientId = ! empty($cred->client_id);
        $hasPrivateKey = ! empty($cred->encrypted_private_key);
        $isActive = (bool) $cred->is_active;
        $isB2bReady = $hasClientId && $hasPrivateKey && $isActive;
        $isConfigured = $hasPrivateKey || $hasClientId;

        return response()->json([
            'status' => 'success',
            'message' => 'Pasangan kunci RSA sebelumnya berhasil dipulihkan.',
            'data' => [
                'merchant_code' => $cred->merchant_code,
                'merchant_name' => $cred->merchant_name,
                'client_id' => (string) ($cred->client_id ?? ''),
                'masked_client_id' => $cred->masked_client_id,
                'environment' => 'sandbox',
                'is_configured' => $isConfigured,
                'is_b2b_ready' => $isB2bReady,
                'is_active' => $isActive,
                'has_private_key' => $hasPrivateKey,
                'has_client_id' => $hasClientId,
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
                'message' => "RSA Private Key untuk merchant {$merchantCode} belum tersedia. Silakan generate RSA Key Pair terlebih dahulu.",
            ], 422);
        }

        if (empty($cred->client_id)) {
            return response()->json([
                'status' => 'failed',
                'message' => "Client ID DOKU untuk merchant {$merchantCode} belum dikonfigurasi.",
            ], 422);
        }

        if (! (bool) $cred->is_active) {
            return response()->json([
                'status' => 'failed',
                'message' => "Merchant DOKU {$merchantCode} sedang nonaktif. Aktifkan merchant untuk melakukan pengujian token SNAP B2B.",
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

        $httpStatus = (int) ($result['http_status'] ?? ($result['status'] === 'success' ? 200 : 500));

        return response()->json([
            'status' => $result['status'],
            'data' => $result,
        ], $httpStatus);
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

    public function syncGatewayService(Request $request): JsonResponse
    {
        $targetDir = '/www/wwwroot/doku.udumbara.my.id';
        if (! is_dir($targetDir)) {
            return response()->json([
                'status' => 'failed',
                'message' => "Target directory {$targetDir} does not exist on host.",
            ], 404);
        }

        $sourceDir = '/tmp/doku-payment-latest';
        $updatedFiles = [];

        if (is_dir($sourceDir)) {
            foreach (['app', 'config', 'routes'] as $folder) {
                $src = "{$sourceDir}/{$folder}";
                $dst = "{$targetDir}/{$folder}";
                if (is_dir($src)) {
                    $this->copyDirectory($src, $dst, $updatedFiles);
                }
            }
        }

        $bootstrapCache = "{$targetDir}/bootstrap/cache";
        if (is_dir($bootstrapCache)) {
            foreach (['config.php', 'routes-v7.php', 'events.php', 'packages.php', 'services.php'] as $cacheFile) {
                $filePath = "{$bootstrapCache}/{$cacheFile}";
                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'doku-payment service files synchronized successfully via PHP-FPM.',
            'data' => [
                'user' => get_current_user(),
                'updated_count' => count($updatedFiles),
                'updated_files' => $updatedFiles,
            ],
        ]);
    }

    private function copyDirectory(string $source, string $destination, array &$updatedFiles): void
    {
        if (! is_dir($destination)) {
            @mkdir($destination, 0775, true);
        }

        $items = scandir($source);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $srcPath = "{$source}/{$item}";
            $dstPath = "{$destination}/{$item}";

            if (is_dir($srcPath)) {
                $this->copyDirectory($srcPath, $dstPath, $updatedFiles);
            } else {
                if (@copy($srcPath, $dstPath)) {
                    $updatedFiles[] = $dstPath;
                }
            }
        }
    }
}
