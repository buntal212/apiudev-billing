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
            $setting->is_production_ready = false;

            return $setting;
        }

        try {
            /** @var DanaEnvironmentSetting */
            return DanaEnvironmentSetting::query()->firstOrCreate(
                ['gateway' => 'dana'],
                [
                    'active_environment' => DanaEnvironmentSetting::EnvironmentSandbox,
                    'is_production_ready' => false,
                ]
            );
        } catch (\Throwable) {
            $setting = new DanaEnvironmentSetting();
            $setting->gateway = 'dana';
            $setting->active_environment = (string) config('services.dana_gateway.environment', DanaEnvironmentSetting::EnvironmentSandbox);
            $setting->is_production_ready = false;

            return $setting;
        }
    }

    public function getActiveEnvironment(): string
    {
        return $this->getSetting()->active_environment;
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
    public function evaluateProductionReadiness(string $primaryMerchantCode = 'udev-central'): array
    {
        $readiness = $this->merchantCatalog->readiness($primaryMerchantCode, DanaEnvironmentSetting::EnvironmentProduction);
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
            $reasonsNotReady[] = "Merchant DANA Production '{$primaryMerchantCode}' belum terdaftar pada katalog gateway.";
        }
        if (! $isActive && $isConfigured) {
            $reasonsNotReady[] = "Merchant DANA Production '{$primaryMerchantCode}' sedang dalam status non-aktif.";
        }
        if (! $isCredentialConfigured) {
            $reasonsNotReady[] = "API Key gateway DANA Production untuk '{$primaryMerchantCode}' belum dikonfigurasi.";
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
}
