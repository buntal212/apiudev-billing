<?php

namespace App\Services;

use App\Models\ApplicationPaymentGateway;
use App\Models\MidtransEnvironmentSetting;
use App\Models\MidtransMerchantCredential;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class MidtransEnvironmentService
{
    public const string ConfirmationPhrase = 'AKTIFKAN PRODUCTION';

    public function __construct(
        private readonly MidtransAuditService $auditService,
        private readonly MidtransSecretStore $secretStore,
    ) {}

    public function getSetting(): MidtransEnvironmentSetting
    {
        /** @var MidtransEnvironmentSetting */
        return MidtransEnvironmentSetting::query()->firstOrCreate(
            ['gateway' => 'midtrans'],
            [
                'active_environment' => MidtransEnvironmentSetting::EnvironmentSandbox,
                'is_production_ready' => false,
            ]
        );
    }

    public function getActiveEnvironment(): string
    {
        return $this->getSetting()->active_environment;
    }

    public function isProductionActive(): bool
    {
        return $this->getActiveEnvironment() === MidtransEnvironmentSetting::EnvironmentProduction;
    }

    /**
     * Evaluate whether Production environment is ready to be activated.
     *
     * @return array{
     *     is_ready: bool,
     *     checks: array<string, mixed>,
     *     reasons_not_ready: list<string>
     * }
     */
    public function evaluateProductionReadiness(string $primaryMerchantCode = 'udev-central'): array
    {
        $cred = MidtransMerchantCredential::query()
            ->where('merchant_code', $primaryMerchantCode)
            ->where('environment', MidtransEnvironmentSetting::EnvironmentProduction)
            ->first();

        $hasServerKey = ! empty($cred?->encrypted_server_key);
        $hasClientKey = ! empty($cred?->client_key);
        $isMerchantActive = (bool) ($cred?->is_active ?? false);

        // Verify Server Key can be decrypted
        $isServerKeyDecrypted = false;
        if ($hasServerKey) {
            try {
                $decrypted = $cred->getServerKey($this->secretStore);
                $isServerKeyDecrypted = ! empty($decrypted);
            } catch (\Throwable) {
                $isServerKeyDecrypted = false;
            }
        }

        $isCredentialsReady = $hasServerKey && $hasClientKey && $isMerchantActive && $isServerKeyDecrypted;

        // Connection test check
        $lastTestStatus = $cred?->last_test_status;
        $isConnectionReady = ($lastTestStatus === 'success');

        // Application mapping check
        $activeMappings = ApplicationPaymentGateway::query()
            ->with('application:id,code,name')
            ->where('provider', 'midtrans')
            ->where('environment', MidtransEnvironmentSetting::EnvironmentProduction)
            ->where('is_active', true)
            ->get();

        $mappingCount = $activeMappings->count();
        $isMappingReady = $mappingCount > 0;

        $reasonsNotReady = [];
        if (! $hasServerKey || ! $isServerKeyDecrypted) {
            $reasonsNotReady[] = 'Server Key Production untuk merchant '.$primaryMerchantCode.' belum dikonfigurasi atau tidak dapat didekripsi.';
        }
        if (! $hasClientKey) {
            $reasonsNotReady[] = 'Client Key Production untuk merchant '.$primaryMerchantCode.' belum dikonfigurasi.';
        }
        if (! $isMerchantActive && $cred !== null) {
            $reasonsNotReady[] = 'Merchant Production '.$primaryMerchantCode.' dalam status non-aktif.';
        }
        if (! $isConnectionReady) {
            $reasonsNotReady[] = 'Uji koneksi API Production belum berhasil dijalankan. Lakukan uji koneksi terlebih dahulu.';
        }
        if (! $isMappingReady) {
            $reasonsNotReady[] = 'Belum ada mapping aplikasi aktif untuk Midtrans Production.';
        }

        $isReady = empty($reasonsNotReady);

        return [
            'is_ready' => $isReady,
            'checks' => [
                'credentials' => [
                    'configured' => $isCredentialsReady,
                    'has_server_key' => $hasServerKey,
                    'has_client_key' => $hasClientKey,
                    'is_active' => $isMerchantActive,
                    'merchant_code' => $primaryMerchantCode,
                    'message' => $isCredentialsReady
                        ? 'Kredensial Server Key dan Client Key Production terenkripsi dengan aman.'
                        : 'Kredensial Production belum lengkap.',
                ],
                'connection' => [
                    'tested' => ! empty($cred?->last_tested_at),
                    'is_connected' => $isConnectionReady,
                    'status' => $lastTestStatus,
                    'tested_at' => $cred?->last_tested_at?->format('Y-m-d H:i:s'),
                    'message' => $isConnectionReady
                        ? 'Uji koneksi ke Midtrans Core API Production berhasil.'
                        : 'Uji koneksi ke Midtrans Core API Production belum berhasil.',
                ],
                'mapping' => [
                    'is_mapped' => $isMappingReady,
                    'active_count' => $mappingCount,
                    'mapped_applications' => $activeMappings->pluck('application.name')->filter()->values()->all(),
                    'message' => $isMappingReady
                        ? "Terdapat {$mappingCount} mapping aplikasi aktif untuk Midtrans Production."
                        : 'Belum ada mapping aplikasi aktif untuk Midtrans Production.',
                ],
            ],
            'reasons_not_ready' => $reasonsNotReady,
        ];
    }

    /**
     * Activate Production environment with mandatory admin confirmation.
     * Never activates automatically!
     *
     * @throws ValidationException
     */
    public function activateProduction(User $adminUser, string $confirmationPhrase, ?string $notes = null): MidtransEnvironmentSetting
    {
        if (! $adminUser->is_admin) {
            throw ValidationException::withMessages([
                'authorization' => ['Hanya akun administrator yang berwenang mengaktifkan environment Production.'],
            ]);
        }

        $trimmedPhrase = trim(strtoupper($confirmationPhrase));
        if ($trimmedPhrase !== self::ConfirmationPhrase) {
            throw ValidationException::withMessages([
                'confirmation' => ['Frasa konfirmasi aktivasi Production tidak cocok. Ketik tepat: '.self::ConfirmationPhrase],
                'confirmation_phrase' => ['Frasa konfirmasi aktivasi Production tidak cocok. Ketik tepat: '.self::ConfirmationPhrase],
            ]);
        }

        $readiness = $this->evaluateProductionReadiness();
        if (! $readiness['is_ready']) {
            throw ValidationException::withMessages([
                'readiness' => array_merge(
                    ['Aktivasi Production ditolak karena persyaratan kesiapan belum terpenuhi:'],
                    $readiness['reasons_not_ready']
                ),
            ]);
        }

        $setting = $this->getSetting();
        $setting->update([
            'active_environment' => MidtransEnvironmentSetting::EnvironmentProduction,
            'is_production_ready' => true,
            'production_activated_at' => now(),
            'production_activated_by' => $adminUser->id,
            'last_switched_at' => now(),
            'last_switched_by' => $adminUser->id,
            'activation_notes' => $notes ?: 'Diaktifkan melalui konfirmasi admin',
        ]);

        $this->auditService->record(
            merchantCode: 'udev-central',
            action: 'environment.production_activated',
            status: 'success',
            metadata: [
                'activated_by_id' => $adminUser->id,
                'activated_by_name' => $adminUser->name,
                'notes' => $notes,
            ]
        );

        return $setting->fresh(['productionActivatedBy', 'lastSwitchedBy']);
    }

    /**
     * Switch back to Sandbox environment.
     *
     * @throws ValidationException
     */
    public function switchToSandbox(User $adminUser, ?string $notes = null): MidtransEnvironmentSetting
    {
        if (! $adminUser->is_admin) {
            throw ValidationException::withMessages([
                'authorization' => ['Hanya akun administrator yang berwenang mengubah environment.'],
            ]);
        }

        $setting = $this->getSetting();
        $setting->update([
            'active_environment' => MidtransEnvironmentSetting::EnvironmentSandbox,
            'last_switched_at' => now(),
            'last_switched_by' => $adminUser->id,
            'activation_notes' => $notes ?: 'Dikembalikan ke Sandbox',
        ]);

        $this->auditService->record(
            merchantCode: 'udev-central',
            action: 'environment.switched_to_sandbox',
            status: 'success',
            metadata: [
                'switched_by_id' => $adminUser->id,
                'switched_by_name' => $adminUser->name,
                'notes' => $notes,
            ]
        );

        return $setting->fresh(['productionActivatedBy', 'lastSwitchedBy']);
    }
}
