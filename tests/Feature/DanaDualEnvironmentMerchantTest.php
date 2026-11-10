<?php

namespace Tests\Feature;

use App\Models\DanaMerchantProvisioningAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class DanaDualEnvironmentMerchantTest extends TestCase
{
    use RefreshDatabase;

    private const string ProvisioningServiceToken = 'test-token';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.dana_merchant_provisioning', [
            'base_url' => 'https://dana-gateway.test',
            'service_token' => self::ProvisioningServiceToken,
            'timeout' => 8,
            'connect_timeout' => 3,
        ]);

        config()->set('services.dana_merchant_secret_store', [
            'active_key_version' => 'v1',
            'keys' => [
                'v1' => 'base64:'.base64_encode(str_repeat('s', 32)),
            ],
        ]);
    }

    public function test_merchant_can_be_provisioned_without_environment_field(): void
    {
        Http::fake([
            'https://dana-gateway.test/api/v1/internal/merchants' => Http::response([
                'data' => [
                    'merchant_id' => 88,
                    'merchant_code' => 'MRC-SINGLE-01',
                    'merchant_name' => 'Single Business Merchant',
                    'environment' => 'sandbox',
                    'merchant_status' => 'active',
                    'provisioning_status' => 'provisioned',
                    'credential_status' => 'pending',
                    'dana_configuration_status' => 'not_configured',
                    'provisioned_at' => now()->toIso8601String(),
                ],
            ], Response::HTTP_CREATED),
        ]);

        $this->actingAdmin()
            ->postJson('/api/v1/admin/dana-gateway-merchants', [
                'code' => 'MRC-SINGLE-01',
                'name' => 'Single Business Merchant',
                'email' => 'contact@merchant.test',
            ])
            ->assertStatus(Response::HTTP_CREATED)
            ->assertJsonPath('data.merchant_code', 'MRC-SINGLE-01')
            ->assertJsonPath('data.environment', 'sandbox');

        $this->assertDatabaseHas('dana_merchant_provisioning_audits', [
            'merchant_code' => 'MRC-SINGLE-01',
            'gateway_merchant_id' => 88,
            'environment' => 'sandbox',
            'status' => 'succeeded',
        ]);
    }

    public function test_merchant_readiness_queries_independent_environments(): void
    {
        DanaMerchantProvisioningAudit::factory()->create([
            'operation' => 'dana_merchant.provision',
            'merchant_code' => 'MRC-DUAL-01',
            'gateway_merchant_id' => 88,
            'environment' => 'sandbox',
            'status' => 'succeeded',
        ]);

        Http::fake([
            'https://dana-gateway.test/api/v1/internal/merchants/88/readiness?environment=sandbox' => Http::response([
                'data' => [
                    'merchant_code' => 'MRC-DUAL-01',
                    'merchant_status' => 'active',
                    'environment' => 'sandbox',
                    'active_api_key_count' => 1,
                    'dana_configuration' => ['available' => true, 'enabled' => true, 'ready' => true],
                    'readiness_status' => 'ready',
                    'not_ready_components' => [],
                ],
            ]),
            'https://dana-gateway.test/api/v1/internal/merchants/88/readiness?environment=production' => Http::response([
                'data' => [
                    'merchant_code' => 'MRC-DUAL-01',
                    'merchant_status' => 'active',
                    'environment' => 'production',
                    'active_api_key_count' => 0,
                    'dana_configuration' => ['available' => false, 'enabled' => false, 'ready' => false],
                    'readiness_status' => 'not_ready',
                    'not_ready_components' => ['dana_configuration', 'active_api_key'],
                ],
            ]),
        ]);

        // Query sandbox readiness
        $this->actingAdmin()
            ->getJson('/api/v1/admin/dana-gateway-merchants/88/readiness?environment=sandbox')
            ->assertOk()
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.readiness_status', 'ready');

        // Query production readiness
        $this->actingAdmin()
            ->getJson('/api/v1/admin/dana-gateway-merchants/88/readiness?environment=production')
            ->assertOk()
            ->assertJsonPath('data.environment', 'production')
            ->assertJsonPath('data.readiness_status', 'not_ready');
    }

    public function test_merchant_configuration_allows_both_sandbox_and_production(): void
    {
        DanaMerchantProvisioningAudit::factory()->create([
            'operation' => 'dana_merchant.provision',
            'merchant_code' => 'MRC-DUAL-01',
            'gateway_merchant_id' => 88,
            'environment' => 'sandbox',
            'status' => 'succeeded',
        ]);

        Http::fake([
            'https://dana-gateway.test/api/v1/internal/merchants/88/dana-configuration' => function (HttpRequest $request) {
                $env = $request['environment'];

                return Http::response([
                    'data' => [
                        'merchant_code' => 'MRC-DUAL-01',
                        'environment' => $env,
                        'dana_configuration' => [
                            'available' => true,
                            'enabled' => true,
                            'ready' => true,
                        ],
                    ],
                ]);
            },
        ]);

        $basePayload = [
            'partner_id' => '200000000001',
            'merchant_id' => '216620000000000000001',
            'origin' => 'https://dual.merchant.test',
            'channel_id' => 'DANA_ONLINE',
            'notification_url' => 'https://dual.merchant.test/notify',
            'callback_url' => 'https://dual.merchant.test/finish',
            'mcc' => '5411',
            'enabled' => true,
        ];

        // Configure Sandbox
        $this->actingAdmin()
            ->putJson('/api/v1/admin/dana-gateway-merchants/88/dana-configuration', [
                ...$basePayload,
                'environment' => 'sandbox',
            ])
            ->assertOk()
            ->assertJsonPath('data.environment', 'sandbox');

        // Configure Production
        $this->actingAdmin()
            ->putJson('/api/v1/admin/dana-gateway-merchants/88/dana-configuration', [
                ...$basePayload,
                'environment' => 'production',
            ])
            ->assertOk()
            ->assertJsonPath('data.environment', 'production');
    }

    public function test_issue_api_key_supports_both_sandbox_and_production_environments(): void
    {
        DanaMerchantProvisioningAudit::factory()->create([
            'operation' => 'dana_merchant.provision',
            'merchant_code' => 'MRC-DUAL-01',
            'gateway_merchant_id' => 88,
            'environment' => 'sandbox',
            'status' => 'succeeded',
        ]);

        Http::fake([
            'https://dana-gateway.test/api/v1/internal/merchants/88/api-keys' => function (HttpRequest $request) {
                return Http::response([
                    'data' => [
                        'api_key' => 'dana_live_plain_test_key_123',
                        'issuance' => [
                            'id' => rand(10, 99),
                            'merchant_api_key_id' => rand(100, 999),
                            'key_prefix' => 'dana_live_test',
                        ],
                    ],
                ]);
            },
            'https://dana-gateway.test/api/v1/internal/merchants/88/api-key-issuances/*/confirm' => Http::response([
                'data' => ['status' => 'confirmed'],
            ]),
        ]);

        // Issue sandbox key
        $this->actingAdmin()
            ->postJson('/api/v1/admin/dana-gateway-merchants/88/api-keys/issue', [
                'environment' => 'sandbox',
                'name' => 'Sandbox Key',
                'abilities' => ['payments:create'],
            ], ['Idempotency-Key' => 'idemp-key-sandbox-01'])
            ->assertOk();

        // Issue production key
        $this->actingAdmin()
            ->postJson('/api/v1/admin/dana-gateway-merchants/88/api-keys/issue', [
                'environment' => 'production',
                'name' => 'Production Key',
                'abilities' => ['payments:create'],
            ], ['Idempotency-Key' => 'idemp-key-production-01'])
            ->assertOk();

        $this->assertDatabaseHas('dana_merchant_api_key_issuances', [
            'gateway_merchant_id' => 88,
            'environment' => 'sandbox',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('dana_merchant_api_key_issuances', [
            'gateway_merchant_id' => 88,
            'environment' => 'production',
            'status' => 'active',
        ]);
    }

    private function actingAdmin(): self
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        return $this;
    }
}
