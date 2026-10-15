<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationPaymentGateway;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicationPaymentGatewayApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.dana_gateway', [
            'environment' => 'sandbox',
            'base_urls' => [
                'sandbox' => 'https://gateway.sandbox.test',
                'production' => 'https://gateway.production.test',
            ],
            'merchant_api_keys' => [
                'sandbox' => [
                    'merchant-bersama' => 'sandbox-secret',
                    'merchant-nonaktif' => 'inactive-secret',
                ],
            ],
            'merchants' => [
                'sandbox' => [
                    'merchant-bersama' => ['name' => 'Merchant Bersama', 'is_active' => true],
                    'merchant-nonaktif' => ['name' => 'Merchant Nonaktif', 'is_active' => false],
                ],
                'production' => [
                    'merchant-produksi' => ['name' => 'Merchant Produksi', 'is_active' => true],
                ],
            ],
        ]);
    }

    public function test_non_admin_cannot_manage_payment_gateway_mappings(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['admin:access']);

        $this->getJson('/api/v1/admin/payment-gateways')
            ->assertForbidden();
    }

    public function test_admin_can_create_mapping_and_share_one_merchant_with_many_applications(): void
    {
        $firstApplication = Application::factory()->create();
        $secondApplication = Application::factory()->create();
        $this->actingAdmin();

        $this->postJson('/api/v1/admin/payment-gateways', $this->payload($firstApplication))
            ->assertCreated()
            ->assertJsonPath('data.application_id', $firstApplication->id)
            ->assertJsonPath('data.gateway_merchant_code', 'merchant-bersama')
            ->assertJsonPath('data.readiness.ready', true);
        $this->postJson('/api/v1/admin/payment-gateways', $this->payload($secondApplication))
            ->assertCreated();

        $this->assertDatabaseCount('application_payment_gateways', 2);
    }

    public function test_duplicate_mapping_for_same_application_provider_and_environment_is_rejected(): void
    {
        $application = Application::factory()->create();
        ApplicationPaymentGateway::query()->create($this->payload($application));
        $this->actingAdmin();

        $this->postJson('/api/v1/admin/payment-gateways', $this->payload($application))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('application_id');
    }

    public function test_unknown_inactive_and_wrong_environment_merchants_are_rejected(): void
    {
        $application = Application::factory()->create();
        $this->actingAdmin();

        $this->postJson('/api/v1/admin/payment-gateways', $this->payload($application, [
            'gateway_merchant_code' => 'tidak-terdaftar',
        ]))->assertUnprocessable()->assertJsonValidationErrors('gateway_merchant_code');

        $this->postJson('/api/v1/admin/payment-gateways', $this->payload($application, [
            'gateway_merchant_code' => 'merchant-nonaktif',
        ]))->assertUnprocessable()->assertJsonValidationErrors('gateway_merchant_code');

        $this->postJson('/api/v1/admin/payment-gateways', $this->payload($application, [
            'environment' => 'production',
            'gateway_merchant_code' => 'merchant-bersama',
        ]))->assertUnprocessable()->assertJsonValidationErrors('gateway_merchant_code');
    }

    public function test_provider_and_environment_are_validated(): void
    {
        $application = Application::factory()->create();
        $this->actingAdmin();

        $this->postJson('/api/v1/admin/payment-gateways', $this->payload($application, [
            'provider' => 'provider-lain',
            'environment' => 'development',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['provider', 'environment']);
    }

    public function test_merchant_options_and_mapping_responses_do_not_expose_credentials(): void
    {
        $application = Application::factory()->create();
        $this->actingAdmin();

        $this->getJson('/api/v1/admin/payment-gateways/merchants?environment=sandbox')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'merchant-bersama')
            ->assertDontSee('sandbox-secret')
            ->assertDontSee('inactive-secret');

        $this->postJson('/api/v1/admin/payment-gateways', $this->payload($application))
            ->assertCreated()
            ->assertDontSee('sandbox-secret')
            ->assertDontSee('inactive-secret');
    }

    public function test_update_does_not_change_historical_payment_attempts(): void
    {
        $application = Application::factory()->create();
        $gateway = ApplicationPaymentGateway::query()->create($this->payload($application));
        $paymentAttempt = PaymentAttempt::factory()->create([
            'gateway_merchant_code' => 'merchant-bersama',
            'gateway_environment' => 'sandbox',
            'gateway_payment_id' => '01J00000000000000000000000',
        ]);
        $this->actingAdmin();

        $this->patchJson("/api/v1/admin/payment-gateways/{$gateway->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('payment_attempts', [
            'id' => $paymentAttempt->id,
            'gateway_merchant_code' => 'merchant-bersama',
            'gateway_environment' => 'sandbox',
            'gateway_payment_id' => '01J00000000000000000000000',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(Application $application, array $overrides = []): array
    {
        return [
            'application_id' => $application->id,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'merchant-bersama',
            'is_active' => true,
            ...$overrides,
        ];
    }

    private function actingAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['admin:access']);
    }
}
