<?php

namespace App\Services;

use App\Models\ApplicationPaymentGateway;
use App\Models\DanaEnvironmentSetting;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DanaEnvironmentService
{
    public const string ConfirmationPhrase = 'AKTIFKAN PRODUCTION';

    public function __construct(
        private readonly DanaGatewayMerchantCatalog $merchantCatalog,
    ) {}

    public function getSetting(): DanaEnvironmentSetting
    {
        if (! Schema::hasTable('dana_environment_settings')) {
            $setting = new DanaEnvironmentSetting();
            $setting->gateway = 'dana';
            $setting->active_environment = (string) config('services.dana_gateway.environment', DanaEnvironmentSetting::EnvironmentSandbox);
            $setting->primary_merchant_code = DanaEnvironmentSetting::getPrimaryMerchantCode();
            $setting->is_production_ready = false;

            return $setting;
        }

        try {
            $hasPrimaryColumn = Schema::hasColumn('dana_environment_settings', 'primary_merchant_code');
            $attributes = [
                'active_environment' => DanaEnvironmentSetting::EnvironmentSandbox,
                'is_production_ready' => false,
            ];
            if ($hasPrimaryColumn) {
                $attributes['primary_merchant_code'] = DanaEnvironmentSetting::getPrimaryMerchantCode();
            }

            /** @var DanaEnvironmentSetting $setting */
            $setting = DanaEnvironmentSetting::query()->firstOrCreate(
                ['gateway' => 'dana'],
                $attributes
            );

            if (! $hasPrimaryColumn && empty($setting->primary_merchant_code)) {
                $setting->primary_merchant_code = DanaEnvironmentSetting::getPrimaryMerchantCode();
            }

            return $setting;
        } catch (\Throwable) {
            $setting = new DanaEnvironmentSetting();
            $setting->gateway = 'dana';
            $setting->active_environment = (string) config('services.dana_gateway.environment', DanaEnvironmentSetting::EnvironmentSandbox);
            $setting->primary_merchant_code = DanaEnvironmentSetting::getPrimaryMerchantCode();
            $setting->is_production_ready = false;

            return $setting;
        }
    }

    public function getActiveEnvironment(): string
    {
        return $this->getSetting()->active_environment;
    }

    public function getPrimaryMerchantCode(?string $environment = null): string
    {
        $env = $environment ?: $this->getActiveEnvironment();
        $setting = $this->getSetting();

        if (is_string($setting->primary_merchant_code) && trim($setting->primary_merchant_code) !== '') {
            return trim($setting->primary_merchant_code);
        }

        return DanaEnvironmentSetting::getPrimaryMerchantCode($env);
    }

    public function isProductionActive(): bool
    {
        return $this->getActiveEnvironment() === DanaEnvironmentSetting::EnvironmentProduction;
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
    public function evaluateProductionReadiness(?string $primaryMerchantCode = null): array
    {
        $primaryCode = $primaryMerchantCode ?: $this->getPrimaryMerchantCode(DanaEnvironmentSetting::EnvironmentProduction);
        $readiness = $this->merchantCatalog->readiness($primaryCode, DanaEnvironmentSetting::EnvironmentProduction);
        $isConfigured = $readiness['merchant_configured'];
        $isActive = $readiness['merchant_active'];
        $isCredentialConfigured = $readiness['credential_configured'];
        $isBaseUrlConfigured = $readiness['base_url_configured'];
        $isPrimaryReady = $readiness['ready'];

        // Application mapping check for production
        $activeMappings = ApplicationPaymentGateway::query()
            ->with('application:id,code,name')
            ->where('provider', 'dana')
            ->where('environment', DanaEnvironmentSetting::EnvironmentProduction)
            ->where('is_active', true)
            ->get();

        $mappingCount = $activeMappings->count();
        $isMappingReady = $mappingCount > 0;

        $reasonsNotReady = [];
        if (! $isConfigured) {
            $reasonsNotReady[] = "Merchant DANA Production '{$primaryCode}' belum terdaftar pada katalog gateway.";
        }
        if (! $isActive && $isConfigured) {
            $reasonsNotReady[] = "Merchant DANA Production '{$primaryCode}' sedang dalam status non-aktif.";
        }
        if (! $isCredentialConfigured) {
            $reasonsNotReady[] = "API Key gateway DANA Production untuk '{$primaryCode}' belum dikonfigurasi.";
        }
        if (! $isBaseUrlConfigured) {
            $reasonsNotReady[] = 'Base URL gateway DANA Production belum dikonfigurasi.';
        }
        if (! $isMappingReady) {
            $reasonsNotReady[] = 'Belum ada mapping aplikasi aktif untuk DANA Production.';
        }

        $isReady = empty($reasonsNotReady);

        return [
            'is_ready' => $isReady,
            'checks' => [
                'merchant' => [
                    'configured' => $isConfigured,
                    'active' => $isActive,
                    'merchant_code' => $primaryMerchantCode,
                    'message' => $isConfigured
                        ? ($isActive ? "Merchant {$primaryMerchantCode} aktif di Production." : "Merchant {$primaryMerchantCode} non-aktif.")
                        : "Merchant {$primaryMerchantCode} belum terdaftar di Production.",
                ],
                'credentials' => [
                    'configured' => $isCredentialConfigured,
                    'base_url' => $isBaseUrlConfigured,
                    'message' => ($isCredentialConfigured && $isBaseUrlConfigured)
                        ? 'Kredensial API Key dan Base URL Production siap.'
                        : 'Kredensial API Key atau Base URL Production belum lengkap.',
                ],
                'mapping' => [
                    'is_mapped' => $isMappingReady,
                    'active_count' => $mappingCount,
                    'mapped_applications' => $activeMappings->pluck('application.name')->filter()->values()->all(),
                    'message' => $isMappingReady
                        ? "Terdapat {$mappingCount} mapping aplikasi aktif untuk DANA Production."
                        : 'Belum ada mapping aplikasi aktif untuk DANA Production.',
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
    public function activateProduction(User $adminUser, string $confirmationPhrase, ?string $notes = null): DanaEnvironmentSetting
    {
        if (! ($adminUser->is_admin ?? false)) {
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
                    ['Aktivasi DANA Production ditolak karena persyaratan kesiapan belum terpenuhi:'],
                    $readiness['reasons_not_ready']
                ),
            ]);
        }

        $setting = $this->getSetting();
        if ($setting->exists) {
            $setting->update([
                'active_environment' => DanaEnvironmentSetting::EnvironmentProduction,
                'is_production_ready' => true,
                'production_activated_at' => now(),
                'production_activated_by' => $adminUser->id,
                'last_switched_at' => now(),
                'last_switched_by' => $adminUser->id,
                'activation_notes' => $notes ?: 'Diaktifkan melalui konfirmasi admin',
            ]);
        } else {
            $setting->active_environment = DanaEnvironmentSetting::EnvironmentProduction;
            $setting->is_production_ready = true;
        }

        return $setting;
    }

    /**
     * Switch back to Sandbox environment.
     *
     * @throws ValidationException
     */
    public function switchToSandbox(User $adminUser, ?string $notes = null): DanaEnvironmentSetting
    {
        if (! ($adminUser->is_admin ?? false)) {
            throw ValidationException::withMessages([
                'authorization' => ['Hanya akun administrator yang berwenang mengubah environment.'],
            ]);
        }

        $setting = $this->getSetting();
        if ($setting->exists) {
            $setting->update([
                'active_environment' => DanaEnvironmentSetting::EnvironmentSandbox,
                'last_switched_at' => now(),
                'last_switched_by' => $adminUser->id,
                'activation_notes' => $notes ?: 'Dikembalikan ke Sandbox',
            ]);
        } else {
            $setting->active_environment = DanaEnvironmentSetting::EnvironmentSandbox;
        }

        return $setting;
    }

    /**
     * Switch the active primary merchant for UDEV subscriptions.
     * Candidate must be verified for active status, credentials, and checkout readiness before activation.
     *
     * @throws ValidationException
     */
    public function setPrimaryMerchant(string $merchantCode, User $adminUser, ?string $notes = null): DanaEnvironmentSetting
    {
        if (! ($adminUser->is_admin ?? false)) {
            throw ValidationException::withMessages([
                'authorization' => ['Hanya akun administrator yang berwenang mengubah Merchant Utama DANA.'],
            ]);
        }

        $merchantCode = trim($merchantCode);
        if ($merchantCode === '') {
            throw ValidationException::withMessages([
                'merchant_code' => ['Kode merchant tidak boleh kosong.'],
            ]);
        }

        $currentEnv = $this->getActiveEnvironment();
        $sandboxReadiness = $this->merchantCatalog->readiness($merchantCode, DanaEnvironmentSetting::EnvironmentSandbox);
        if (! $sandboxReadiness['ready']) {
            $reasons = [];
            if (! $sandboxReadiness['merchant_configured']) {
                $reasons[] = "Merchant '{$merchantCode}' belum terdaftar pada katalog gateway Sandbox.";
            }
            if (! $sandboxReadiness['merchant_active'] && $sandboxReadiness['merchant_configured']) {
                $reasons[] = "Merchant '{$merchantCode}' sedang dalam status nonaktif di Sandbox.";
            }
            if (! $sandboxReadiness['credential_configured']) {
                $reasons[] = "API Key gateway Sandbox untuk merchant '{$merchantCode}' belum dikonfigurasi.";
            }
            if (! $sandboxReadiness['base_url_configured']) {
                $reasons[] = 'Base URL gateway Sandbox belum dikonfigurasi.';
            }

            throw ValidationException::withMessages([
                'merchant_code' => array_merge(
                    ["Merchant '{$merchantCode}' belum memenuhi persyaratan kesiapan Sandbox untuk dijadikan Merchant Utama:"],
                    $reasons
                ),
            ]);
        }

        if ($currentEnv === DanaEnvironmentSetting::EnvironmentProduction) {
            $prodReadiness = $this->merchantCatalog->readiness($merchantCode, DanaEnvironmentSetting::EnvironmentProduction);
            if (! $prodReadiness['ready']) {
                $reasons = [];
                if (! $prodReadiness['merchant_configured']) {
                    $reasons[] = "Merchant '{$merchantCode}' belum terdaftar pada katalog gateway Production.";
                }
                if (! $prodReadiness['merchant_active'] && $prodReadiness['merchant_configured']) {
                    $reasons[] = "Merchant '{$merchantCode}' sedang dalam status nonaktif di Production.";
                }
                if (! $prodReadiness['credential_configured']) {
                    $reasons[] = "API Key gateway Production untuk merchant '{$merchantCode}' belum dikonfigurasi.";
                }
                if (! $prodReadiness['base_url_configured']) {
                    $reasons[] = 'Base URL gateway Production belum dikonfigurasi.';
                }

                throw ValidationException::withMessages([
                    'merchant_code' => array_merge(
                        ["Environment aktif adalah Production, namun merchant '{$merchantCode}' belum siap untuk Production:"],
                        $reasons
                    ),
                ]);
            }
        }

        $setting = $this->getSetting();
        $updates = [
            'last_switched_at' => now(),
            'last_switched_by' => $adminUser->id,
            'activation_notes' => $notes ?: "Merchant Utama DANA diubah menjadi {$merchantCode}",
        ];

        if ($setting->exists) {
            if (Schema::hasColumn('dana_environment_settings', 'primary_merchant_code')) {
                $updates['primary_merchant_code'] = $merchantCode;
            }
            $setting->update($updates);
        }

        $setting->primary_merchant_code = $merchantCode;
        config(['services.udev_billing.subscription_dana_sandbox_merchant_code' => $merchantCode]);
        if ($currentEnv === DanaEnvironmentSetting::EnvironmentProduction) {
            config(['services.udev_billing.subscription_dana_production_merchant_code' => $merchantCode]);
        }

        return $setting;
    }
}
