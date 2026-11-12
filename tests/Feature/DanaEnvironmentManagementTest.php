<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationPaymentGateway;
use App\Models\DanaEnvironmentSetting;
use App\Models\User;
use App\Services\DanaEnvironmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class DanaEnvironmentManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.dana_gateway', [
            'environment' => 'sandbox',
            'base_urls' => [
                'sandbox' => 'https://gateway-sandbox.test',
                'production' => 'https://dana.udumbara.my.id',
            ],
            'timeout' => 8,
            'connect_timeout' => 3,
            'merchant_api_keys' => [
                'sandbox' => [
                    'udev-central' => 'key-central-sandbox',
                ],
                'production' => [
                    'udev-central' => 'key-central-prod',
                ],
            ],
            'merchants' => [
                'sandbox' => [
                    'udev-central' => ['name' => 'UDEV Central Sandbox', 'is_active' => true],
                ],
                'production' => [
                    'udev-central' => ['name' => 'UDEV Central Prod', 'is_active' => true],
                ],
            ],
        ]);

        config()->set('services.udev_billing.subscription_dana_sandbox_merchant_code', 'udev-central');
        config()->set('services.udev_billing.subscription_dana_production_merchant_code', 'udev-central');

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->regularUser = User::factory()->create(['is_admin' => false]);
    }

    public function test_get_environment_returns_valid_data_and_non_null_merchant_code(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $response = $this->getJson('/api/v1/admin/dana-gateway-merchants/environment');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.active_environment', 'sandbox')
            ->assertJsonPath('data.primary_merchant_code', 'udev-central')
            ->assertJsonPath('data.readiness.checks.merchant.merchant_code', 'udev-central')
            ->assertJsonPath('data.readiness.checks.merchant.configured', true)
            ->assertJsonPath('data.readiness.checks.merchant.active', true);

        // Check merchant message is not empty and contains merchant code
        $message = $response->json('data.readiness.checks.merchant.message');
        $this->assertNotEmpty($message);
        $this->assertStringContainsString('udev-central', $message);
    }

    public function test_activate_production_requires_admin_authorization(): void
    {
        Sanctum::actingAs($this->regularUser, ['*']);

        $this->postJson('/api/v1/admin/dana-gateway-merchants/environment/activate-production', [
            'confirmation_phrase' => 'AKTIFKAN-DANA-PRODUCTION',
        ])->assertForbidden();
    }

    public function test_activate_production_rejects_invalid_confirmation_phrase(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $response = $this->postJson('/api/v1/admin/dana-gateway-merchants/environment/activate-production', [
            'confirmation_phrase' => 'SALAH-FRASA',
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['confirmation_phrase']);
    }

    public function test_activate_production_rejects_when_not_ready(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        // No active production application mappings exist yet
        $response = $this->postJson('/api/v1/admin/dana-gateway-merchants/environment/activate-production', [
            'confirmation_phrase' => 'AKTIFKAN-DANA-PRODUCTION',
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['readiness']);
    }

    public function test_activate_production_succeeds_when_ready_with_confirmation_phrase(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        // Create an active production mapping so readiness passes
        $application = Application::factory()->create(['name' => 'Larissama Prod App']);
        ApplicationPaymentGateway::query()->create([
            'application_id' => $application->id,
            'provider' => 'dana',
            'environment' => 'production',
            'gateway_merchant_code' => 'udev-central',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/admin/dana-gateway-merchants/environment/activate-production', [
            'confirmation_phrase' => 'AKTIFKAN-DANA-PRODUCTION',
            'notes' => 'Aktivasi resmi Production untuk go-live',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', 'Environment DANA Production berhasil diaktifkan.')
            ->assertJsonPath('data.active_environment', 'production')
            ->assertJsonPath('data.is_production_ready', true);

        $this->assertSame('production', DanaEnvironmentSetting::getActiveEnvironment());

        // Verify GET endpoint reflects the activated production state
        $getRes = $this->getJson('/api/v1/admin/dana-gateway-merchants/environment');
        $getRes->assertOk()
            ->assertJsonPath('data.active_environment', 'production')
            ->assertJsonPath('data.is_production_ready', true)
            ->assertJsonPath('data.activation_notes', 'Aktivasi resmi Production untuk go-live');
    }

    public function test_activate_production_also_accepts_legacy_confirmation_phrase(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $application = Application::factory()->create();
        ApplicationPaymentGateway::query()->create([
            'application_id' => $application->id,
            'provider' => 'dana',
            'environment' => 'production',
            'gateway_merchant_code' => 'udev-central',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/admin/dana-gateway-merchants/environment/activate-production', [
            'confirmation_phrase' => 'AKTIFKAN PRODUCTION',
            'notes' => 'Aktivasi dengan frasa legacy',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.active_environment', 'production');
    }

    public function test_switch_sandbox_restores_sandbox_mode(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        // Setup production active first
        $setting = app(DanaEnvironmentService::class)->getSetting();
        $setting->update([
            'active_environment' => 'production',
            'is_production_ready' => true,
        ]);

        $this->assertSame('production', DanaEnvironmentSetting::getActiveEnvironment());

        // Switch back to Sandbox
        $response = $this->postJson('/api/v1/admin/dana-gateway-merchants/environment/switch-sandbox', [
            'notes' => 'Kembali ke mode Sandbox untuk pengujian',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', 'Environment DANA berhasil dikembalikan ke Sandbox.')
            ->assertJsonPath('data.active_environment', 'sandbox');

        $this->assertSame('sandbox', DanaEnvironmentSetting::getActiveEnvironment());
    }
}
