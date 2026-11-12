<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationPaymentGateway;
use App\Models\DokuMerchantCredential;
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
                'production' => [
                    'merchant-produksi' => 'production-secret',
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

    public function test_admin_can_filter_mappings_by_provider_doku_and_dana(): void
    {
        $appDana = Application::factory()->create(['name' => 'App DANA']);
        $appDoku = Application::factory()->create(['name' => 'App DOKU']);

        DokuMerchantCredential::create([
            'merchant_code' => 'doku-store',
            'merchant_name' => 'DOKU Store',
            'client_id' => 'MALL-DOKU-01',
            'encrypted_private_key' => 'encrypted-key',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);

        ApplicationPaymentGateway::query()->create([
            'application_id' => $appDana->id,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'merchant-bersama',
            'is_active' => true,
        ]);

        ApplicationPaymentGateway::query()->create([
            'application_id' => $appDoku->id,
            'provider' => 'doku',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'doku-store',
            'is_active' => true,
        ]);

        $this->actingAdmin();

        // 1. Filter provider=doku harus HTTP 200 dan hanya berisi doku
        $dokuResponse = $this->getJson('/api/v1/admin/payment-gateways?provider=doku&environment=sandbox');
        $dokuResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.provider', 'doku')
            ->assertJsonPath('data.0.gateway_merchant_code', 'doku-store')
            ->assertJsonPath('data.0.readiness.ready', true);

        // 2. Filter provider=dana harus HTTP 200 dan hanya berisi dana
        $danaResponse = $this->getJson('/api/v1/admin/payment-gateways?provider=dana&environment=sandbox');
        $danaResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.provider', 'dana')
            ->assertJsonPath('data.0.gateway_merchant_code', 'merchant-bersama')
            ->assertJsonPath('data.0.readiness.ready', true);
    }

    public function test_admin_can_create_update_and_delete_doku_mapping(): void
    {
        $app = Application::factory()->create();

        DokuMerchantCredential::create([
            'merchant_code' => 'doku-crud',
            'merchant_name' => 'DOKU CRUD Store',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);

        $this->actingAdmin();

        // Create
        $createRes = $this->postJson('/api/v1/admin/payment-gateways', [
            'application_id' => $app->id,
            'provider' => 'doku',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'doku-crud',
            'is_active' => true,
        ])->assertCreated()
            ->assertJsonPath('data.provider', 'doku')
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.gateway_merchant_code', 'doku-crud');

        $mappingId = $createRes->json('data.id');

        // Update
        $this->patchJson("/api/v1/admin/payment-gateways/{$mappingId}", [
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('data.is_active', false);

        // Delete
        $this->deleteJson("/api/v1/admin/payment-gateways/{$mappingId}")
            ->assertOk()
            ->assertJson([
                'status' => 'success',
            ]);

        $this->assertDatabaseMissing('application_payment_gateways', [
            'id' => $mappingId,
        ]);
    }

    public function test_doku_mapping_rejects_production_environment_and_unregistered_merchant(): void
    {
        $app = Application::factory()->create();
        $this->actingAdmin();

        // Reject production for DOKU
        $this->postJson('/api/v1/admin/payment-gateways', [
            'application_id' => $app->id,
            'provider' => 'doku',
            'environment' => 'production',
            'gateway_merchant_code' => 'udev-central',
            'is_active' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('environment');

        // Reject unregistered merchant for DOKU
        $this->postJson('/api/v1/admin/payment-gateways', [
            'application_id' => $app->id,
            'provider' => 'doku',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'merchant-hantu',
            'is_active' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('gateway_merchant_code');
    }

    public function test_payment_gateways_merchant_options_supports_provider_doku(): void
    {
        DokuMerchantCredential::create([
            'merchant_code' => 'doku-opt-test',
            'merchant_name' => 'DOKU Option Test',
            'client_id' => 'MALL-OPT-01',
            'encrypted_private_key' => 'enc-key',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);

        $this->actingAdmin();

        $response = $this->getJson('/api/v1/admin/payment-gateways/merchants?environment=sandbox&provider=doku');
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['code', 'name', 'is_active', 'readiness'],
                ],
            ]);

        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertContains('doku-opt-test', $codes);
    }

    public function test_one_application_can_have_both_dana_sandbox_and_production_mappings_concurrently(): void
    {
        $application = Application::factory()->create(['name' => 'Dual Env App', 'code' => 'dual-env-app']);
        $this->actingAdmin();

        // 1. Create Sandbox mapping
        $resSbx = $this->postJson('/api/v1/admin/payment-gateways', $this->payload($application, [
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'merchant-bersama',
            'is_active' => true,
        ]))->assertCreated()
            ->assertJsonPath('data.application_id', $application->id)
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.gateway_merchant_code', 'merchant-bersama');

        // 2. Create Production mapping on the same application
        $resProd = $this->postJson('/api/v1/admin/payment-gateways', $this->payload($application, [
            'environment' => 'production',
            'gateway_merchant_code' => 'merchant-produksi',
            'is_active' => true,
        ]))->assertCreated()
            ->assertJsonPath('data.application_id', $application->id)
            ->assertJsonPath('data.environment', 'production')
            ->assertJsonPath('data.gateway_merchant_code', 'merchant-produksi');

        $this->assertDatabaseCount('application_payment_gateways', 2);

        // 3. List mappings for this application shows both rows
        $listRes = $this->getJson("/api/v1/admin/payment-gateways?application_id={$application->id}&provider=dana")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $envs = collect($listRes->json('data'))->pluck('environment')->all();
        $this->assertContains('sandbox', $envs);
        $this->assertContains('production', $envs);
    }

    public function test_duplicate_dana_mapping_for_same_environment_is_rejected_with_descriptive_error(): void
    {
        $application = Application::factory()->create();
        $this->actingAdmin();

        // First mapping
        $this->postJson('/api/v1/admin/payment-gateways', $this->payload($application, [
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'merchant-bersama',
        ]))->assertCreated();

        // Duplicate mapping for same application + provider + sandbox
        $this->postJson('/api/v1/admin/payment-gateways', $this->payload($application, [
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'merchant-bersama',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['application_id'])
            ->assertJsonPath('errors.application_id.0', 'Aplikasi sudah memiliki mapping untuk provider dan environment ini.');
    }

    public function test_updating_mapping_environment_to_existing_environment_is_rejected(): void
    {
        $application = Application::factory()->create();
        $this->actingAdmin();

        $sbx = ApplicationPaymentGateway::query()->create($this->payload($application, [
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'merchant-bersama',
        ]));

        $prod = ApplicationPaymentGateway::query()->create($this->payload($application, [
            'environment' => 'production',
            'gateway_merchant_code' => 'merchant-produksi',
        ]));

        // Try to update prod to sandbox (which already exists)
        $this->patchJson("/api/v1/admin/payment-gateways/{$prod->id}", [
            'environment' => 'sandbox',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['environment'])
            ->assertJsonPath('errors.environment.0', 'Aplikasi sudah memiliki mapping untuk provider dan environment ini.');
    }

    public function test_updating_merchant_code_or_status_operates_independently_across_environments(): void
    {
        $application = Application::factory()->create();
        $this->actingAdmin();

        $sbx = ApplicationPaymentGateway::query()->create($this->payload($application, [
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'merchant-bersama',
            'is_active' => true,
        ]));

        $prod = ApplicationPaymentGateway::query()->create($this->payload($application, [
            'environment' => 'production',
            'gateway_merchant_code' => 'merchant-produksi',
            'is_active' => true,
        ]));

        // Update sandbox active state to false
        $this->patchJson("/api/v1/admin/payment-gateways/{$sbx->id}", [
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', false);

        // Prod remains active and intact
        $this->assertDatabaseHas('application_payment_gateways', [
            'id' => $sbx->id,
            'environment' => 'sandbox',
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('application_payment_gateways', [
            'id' => $prod->id,
            'environment' => 'production',
            'is_active' => true,
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
