<?php

namespace Tests\Feature;

use App\Models\DanaMerchantApiKeyIssuance;
use App\Models\DanaMerchantProvisioningAudit;
use App\Models\User;
use App\Services\DanaMerchantApiKeyIssuanceService;
use App\Services\DanaMerchantSecretStoreException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DanaGatewayMerchantManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private const string ServiceToken = 'test-service-token';

    private const string PrivateKey = "-----BEGIN PRIVATE KEY-----\ntest-private-key\n-----END PRIVATE KEY-----";

    private const string DanaWebhookPublicKey = "-----BEGIN PUBLIC KEY-----\ntest-dana-public-key\n-----END PUBLIC KEY-----";

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.dana_merchant_provisioning', [
            'base_url' => 'https://dana-gateway.test',
            'service_token' => self::ServiceToken,
            'timeout' => 8,
            'connect_timeout' => 3,
        ]);
        Http::preventStrayRequests();
    }

    public function test_management_endpoints_return_401_or_403_for_missing_admin_authentication_and_ability(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();

        $this->getJson($this->readinessUrl($merchant->gateway_merchant_id))
            ->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(), ['admin:access']);
        $this->getJson($this->readinessUrl($merchant->gateway_merchant_id))
            ->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
        $this->getJson($this->readinessUrl($merchant->gateway_merchant_id))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_management_endpoints_return_404_without_calling_gateway_for_unknown_or_unprovisioned_merchants(): void
    {
        $this->actingAdmin();

        $this->getJson($this->readinessUrl(999))->assertNotFound();
        $this->getJson($this->readinessUrl(1000))->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_readiness_returns_only_safe_gateway_metadata_and_records_an_administrative_audit(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/readiness" => Http::response([
                'data' => $this->readiness(),
            ]),
        ]);
        $this->actingAdmin();

        $this->getJson($this->readinessUrl($merchant->gateway_merchant_id))
            ->assertOk()
            ->assertJsonPath('data.merchant_code', 'udev-salon')
            ->assertJsonPath('data.dana_configuration.available', false)
            ->assertJsonPath('data.dana_configuration.enabled', false)
            ->assertJsonPath('data.active_api_key_count', 0)
            ->assertJsonFragment(['active_api_key'])
            ->assertJsonMissingPath('data.private_key')
            ->assertJsonMissingPath('data.encrypted_credentials');

        $this->assertDatabaseHas('dana_merchant_provisioning_audits', [
            'operation' => 'dana_merchant.readiness_viewed',
            'gateway_merchant_id' => $merchant->gateway_merchant_id,
            'status' => 'succeeded',
        ]);
    }

    public function test_stored_dana_configuration_returns_safe_metadata_for_the_provisioned_sandbox_merchant_only(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/dana-configuration" => Http::response([
                'data' => [
                    'merchant_code' => $merchant->merchant_code,
                    'environment' => 'sandbox',
                    'configuration_exists' => true,
                    'dana_configuration' => ['available' => true, 'enabled' => false, 'ready' => false],
                    'configuration' => [
                        'partner_id' => 'partner-sandbox',
                        'merchant_id' => 'merchant-sandbox',
                        'origin' => 'https://udev.example',
                        'channel_id' => 'sandbox-channel',
                        'notification_url' => 'https://dana.example/webhook',
                        'callback_url' => 'https://udev.example/callback',
                        'mcc' => '7392',
                        'enabled' => false,
                        'private_key_configured' => true,
                        'dana_webhook_public_key_configured' => true,
                    ],
                ],
            ]),
        ]);
        $this->actingAdmin();

        $this->getJson($this->storedConfigurationUrl($merchant->gateway_merchant_id))
            ->assertOk()
            ->assertHeader('Cache-Control')
            ->assertJsonPath('data.configuration_exists', true)
            ->assertJsonPath('data.configuration.partner_id', 'partner-sandbox')
            ->assertJsonMissingPath('data.configuration.external_store_id')
            ->assertJsonPath('data.configuration.private_key_configured', true)
            ->assertJsonMissingPath('data.configuration.private_key')
            ->assertJsonMissingPath('data.configuration.dana_webhook_public_key');

        Http::assertSent(function (HttpRequest $request): bool {
            $this->assertSame('GET', $request->method());
            $this->assertSame(['Bearer '.self::ServiceToken], $request->header('Authorization'));

            return true;
        });
    }

    public function test_stored_dana_configuration_does_not_call_gateway_for_unprovisioned_merchants(): void
    {
        $this->actingAdmin();

        $this->getJson($this->storedConfigurationUrl(999999))->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_sandbox_configuration_is_idempotent_audited_and_never_returns_or_persists_credentials(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        $idempotencyKeys = [];
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/dana-configuration" => function (HttpRequest $request) use (&$idempotencyKeys): mixed {
                $idempotencyKeys[] = $request->data()['idempotency_key'];

                return Http::response(['data' => $this->configuration()]);
            },
        ]);
        Log::spy();
        $this->actingAdmin();

        $this->putJson($this->configurationUrl($merchant->gateway_merchant_id), $this->configurationPayload())
            ->assertOk()
            ->assertJsonPath('data.merchant_code', 'udev-salon')
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.dana_configuration.ready', true)
            ->assertDontSee(self::PrivateKey)
            ->assertDontSee(self::DanaWebhookPublicKey)
            ->assertDontSee('merchant-sandbox-secret')
            ->assertDontSee(self::ServiceToken);

        $this->putJson($this->configurationUrl($merchant->gateway_merchant_id), $this->configurationPayload())
            ->assertOk()
            ->assertDontSee(self::PrivateKey)
            ->assertDontSee(self::DanaWebhookPublicKey);

        $this->assertCount(2, $idempotencyKeys);
        $this->assertSame($idempotencyKeys[0], $idempotencyKeys[1]);
        $this->assertMatchesRegularExpression('/^udev-dana-configuration-[a-f0-9]{64}$/', $idempotencyKeys[0]);
        Http::assertSent(function (HttpRequest $request): bool {
            $this->assertSame('PUT', $request->method());
            $this->assertSame(['Bearer '.self::ServiceToken], $request->header('Authorization'));
            $this->assertSame(self::PrivateKey, $request->data()['private_key']);

            return true;
        });

        $audits = DanaMerchantProvisioningAudit::query()
            ->where('operation', 'dana_merchant.dana_configuration_updated')
            ->get();
        $this->assertCount(2, $audits);
        foreach ($audits as $audit) {
            $details = json_encode($audit->safe_details, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString(self::PrivateKey, $details);
            $this->assertStringNotContainsString(self::DanaWebhookPublicKey, $details);
            $this->assertStringNotContainsString('merchant-sandbox-secret', $details);
        }
        Log::shouldNotHaveReceived('debug');
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function test_sandbox_configuration_rejects_production_and_unexpected_sensitive_fields_before_gateway_call(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        $this->actingAdmin();

        $this->putJson($this->configurationUrl($merchant->gateway_merchant_id), $this->configurationPayload([
            'environment' => 'production',
            'service_token' => 'frontend-supplied-token',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['environment', 'service_token']);

        Http::assertNothingSent();
        $this->assertDatabaseMissing('dana_merchant_provisioning_audits', [
            'operation' => 'dana_merchant.dana_configuration_updated',
        ]);
    }

    public function test_sandbox_configuration_forwards_portal_key_material_to_the_gateway_for_cryptographic_validation(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        $privateKey = base64_encode('synthetic-private-der-is-validated-by-gateway');
        $publicKey = base64_encode('synthetic-public-der-is-validated-by-gateway');
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/dana-configuration" => function (HttpRequest $request) use ($privateKey, $publicKey): mixed {
                $this->assertSame($privateKey, $request->data()['private_key']);
                $this->assertSame($publicKey, $request->data()['dana_webhook_public_key']);

                return Http::response(['data' => $this->configuration()]);
            },
        ]);
        $this->actingAdmin();

        $this->putJson($this->configurationUrl($merchant->gateway_merchant_id), $this->configurationPayload([
            'private_key' => $privateKey,
            'dana_webhook_public_key' => $publicKey,
        ]))
            ->assertOk()
            ->assertDontSee($privateKey)
            ->assertDontSee($publicKey);
    }

    public function test_gateway_timeout_is_safely_reported_and_audited_without_credential_details(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        Http::fake(fn (): never => throw new ConnectionException('timeout with '.self::PrivateKey));
        $this->actingAdmin();

        $this->putJson($this->configurationUrl($merchant->gateway_merchant_id), $this->configurationPayload())
            ->assertStatus(503)
            ->assertDontSee('timeout')
            ->assertDontSee(self::PrivateKey)
            ->assertDontSee(self::ServiceToken);

        $audit = DanaMerchantProvisioningAudit::query()
            ->where('operation', 'dana_merchant.dana_configuration_updated')
            ->sole();
        $details = json_encode($audit->safe_details, JSON_THROW_ON_ERROR);
        $this->assertSame('failed', $audit->status);
        $this->assertNull($audit->gateway_status);
        $this->assertStringNotContainsString(self::PrivateKey, $details);
    }

    public function test_api_key_metadata_filters_plaintext_and_hash_fields_and_audits_the_read(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/api-keys" => Http::response([
                'data' => [[
                    ...$this->apiKey(['is_active' => false]),
                    'key' => 'dpk_plaintext_test_key',
                    'key_hash' => 'sha256-test-key-hash',
                ]],
            ]),
        ]);
        $this->actingAdmin();

        $this->getJson($this->apiKeysUrl($merchant->gateway_merchant_id))
            ->assertOk()
            ->assertJsonPath('data.0.id', 91)
            ->assertJsonPath('data.0.key_prefix', 'dpk_test')
            ->assertJsonPath('data.0.is_active', false)
            ->assertJsonMissingPath('data.0.key')
            ->assertJsonMissingPath('data.0.key_hash')
            ->assertDontSee('dpk_plaintext_test_key')
            ->assertDontSee('sha256-test-key-hash');

        $this->assertDatabaseHas('dana_merchant_provisioning_audits', [
            'operation' => 'dana_merchant.api_keys_viewed',
            'status' => 'succeeded',
        ]);
    }

    public function test_reconciliation_marks_cancel_pending_issuance_expired_when_gateway_reports_expired(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        $issuance = DanaMerchantApiKeyIssuance::query()->create([
            'admin_user_id' => User::factory()->create()->id,
            'gateway_merchant_id' => $merchant->gateway_merchant_id,
            'merchant_code' => $merchant->merchant_code,
            'environment' => 'sandbox',
            'idempotency_key' => 'test-expired-issuance',
            'request_fingerprint' => hash('sha256', 'test-expired-issuance'),
            'gateway_issuance_id' => 1,
            'gateway_api_key_id' => 3,
            'status' => 'cancel_pending',
        ]);
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/api-key-issuances/1" => Http::response([
                'data' => ['id' => 1, 'merchant_api_key_id' => 3, 'status' => 'expired'],
            ]),
        ]);
        $this->actingAdmin();

        $this->getJson("/api/v1/admin/dana-gateway-merchants/{$merchant->gateway_merchant_id}/api-key-issuances/{$issuance->id}")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.status', 'expired')
            ->assertJsonPath('data.gateway_api_key_id', 3)
            ->assertJsonMissingPath('data.api_key');

        $this->assertDatabaseHas('dana_merchant_api_key_issuances', ['id' => $issuance->id, 'status' => 'expired']);
    }

    public function test_issuance_history_is_paginated_scoped_to_the_selected_merchant_and_safe(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        $admin = User::factory()->create();
        $visible = DanaMerchantApiKeyIssuance::query()->create([
            'admin_user_id' => $admin->id, 'gateway_merchant_id' => $merchant->gateway_merchant_id,
            'merchant_code' => $merchant->merchant_code, 'environment' => 'sandbox',
            'idempotency_key' => 'issuance-history-visible', 'request_fingerprint' => hash('sha256', 'visible'),
            'gateway_issuance_id' => 4, 'gateway_api_key_id' => 8, 'key_prefix' => 'dpk_safe', 'status' => 'cancel_pending',
        ]);
        DanaMerchantApiKeyIssuance::query()->create([
            'admin_user_id' => $admin->id, 'gateway_merchant_id' => 999,
            'merchant_code' => 'other-sandbox', 'environment' => 'sandbox',
            'idempotency_key' => 'issuance-history-hidden', 'request_fingerprint' => hash('sha256', 'hidden'),
            'status' => 'pending',
        ]);
        $this->actingAdmin();

        $this->getJson("/api/v1/admin/dana-gateway-merchants/{$merchant->gateway_merchant_id}/api-key-issuances?per_page=1")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('data.0.gateway_api_key_id', 8)
            ->assertJsonPath('data.0.status', 'cancel_pending')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonMissingPath('data.0.idempotency_key')
            ->assertJsonMissingPath('data.0.request_fingerprint')
            ->assertJsonMissingPath('data.0.api_key');

        $this->assertDatabaseHas('dana_merchant_provisioning_audits', [
            'operation' => 'dana_merchant.api_key_issuances_viewed',
            'gateway_merchant_id' => $merchant->gateway_merchant_id,
        ]);
    }

    public function test_api_key_revocation_requires_confirmation_then_forwards_the_confirmed_idempotent_request(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        $this->actingAdmin();

        $this->deleteJson($this->apiKeyUrl($merchant->gateway_merchant_id, 91))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['confirm_revoke']);
        Http::assertNothingSent();

        $idempotencyKeys = [];
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/api-keys/91" => function (HttpRequest $request) use (&$idempotencyKeys): mixed {
                $idempotencyKeys[] = $request->data()['idempotency_key'];

                return Http::response(['data' => [
                    'id' => 91,
                    'key_prefix' => 'dpk_test',
                    'revoked_at' => '2026-10-16T00:00:00.000000Z',
                ]]);
            },
        ]);

        $this->deleteJson($this->apiKeyUrl($merchant->gateway_merchant_id, 91), ['confirm_revoke' => true])
            ->assertOk()
            ->assertJsonPath('data.id', 91)
            ->assertJsonPath('data.key_prefix', 'dpk_test')
            ->assertJsonMissingPath('data.key_hash');
        $this->deleteJson($this->apiKeyUrl($merchant->gateway_merchant_id, 91), ['confirm_revoke' => true])
            ->assertOk();

        $this->assertCount(2, $idempotencyKeys);
        $this->assertSame($idempotencyKeys[0], $idempotencyKeys[1]);
        Http::assertSent(function (HttpRequest $request): bool {
            $this->assertSame('DELETE', $request->method());
            $this->assertSame(true, $request->data()['confirm_revoke']);

            return true;
        });
        $this->assertDatabaseCount('dana_merchant_provisioning_audits', 3);
        $this->assertDatabaseHas('dana_merchant_provisioning_audits', [
            'operation' => 'dana_merchant.api_key_revoked',
            'status' => 'succeeded',
        ]);
    }

    public function test_larissama_cannot_be_managed_even_if_a_legacy_audit_record_exists(): void
    {
        $merchant = $this->sandboxProvisionedMerchant(['merchant_code' => 'larissama']);
        $this->actingAdmin();

        $this->putJson($this->configurationUrl($merchant->gateway_merchant_id), $this->configurationPayload())
            ->assertNotFound();

        Http::assertNothingSent();
        $this->assertDatabaseCount('dana_merchant_provisioning_audits', 1);
    }

    public function test_api_key_issuance_secret_store_failure_returns_safe_503_without_gateway_or_secret_details(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        $this->mock(DanaMerchantApiKeyIssuanceService::class, function ($mock): void {
            $mock->shouldReceive('issue')->once()->andThrow(new DanaMerchantSecretStoreException('raw secret must not escape'));
        });
        $this->actingAdmin();

        $this->withHeader('Idempotency-Key', 'frontend-action-secret-store-failure-001')->postJson("/api/v1/admin/dana-gateway-merchants/{$merchant->gateway_merchant_id}/api-keys/issue", [
            'environment' => 'sandbox', 'name' => 'Sandbox checkout', 'abilities' => ['payments:create'],
        ])->assertStatus(503)->assertDontSee('raw secret must not escape')->assertJsonPath('message', 'Penyimpanan aman API key belum tersedia. Issuance tidak dikonfirmasi.');

        Http::assertNothingSent();
    }

    /** @return array{gateway_merchant_id: int, merchant_code: string} */
    private function sandboxProvisionedMerchant(array $overrides = []): DanaMerchantProvisioningAudit
    {
        return DanaMerchantProvisioningAudit::factory()->create([
            'operation' => 'dana_merchant.provision',
            'gateway_merchant_id' => 101,
            'merchant_code' => 'udev-salon',
            'environment' => 'sandbox',
            'status' => 'succeeded',
            ...$overrides,
        ]);
    }

    /** @return array{merchant_code: string, merchant_status: string, environment: string, active_api_key_count: int, dana_configuration: array{available: bool, enabled: bool, ready: bool}, readiness_status: string, not_ready_components: list<string>} */
    private function readiness(): array
    {
        return [
            'merchant_code' => 'udev-salon',
            'merchant_status' => 'active',
            'environment' => 'sandbox',
            'active_api_key_count' => 0,
            'dana_configuration' => ['available' => false, 'enabled' => false, 'ready' => false],
            'readiness_status' => 'not_ready',
            'not_ready_components' => ['active_api_key', 'dana_configuration'],
        ];
    }

    /** @return array{merchant_code: string, environment: string, dana_configuration: array{available: bool, enabled: bool, ready: bool}} */
    private function configuration(): array
    {
        return [
            'merchant_code' => 'udev-salon',
            'environment' => 'sandbox',
            'dana_configuration' => ['available' => true, 'enabled' => true, 'ready' => true],
        ];
    }

    /** @return array{id: int, name: string, key_prefix: string, abilities: list<string>, expires_at: null, revoked_at: null, last_used_at: null, created_at: string} */
    private function apiKey(array $overrides = []): array
    {
        return [
            'id' => 91,
            'name' => 'Sandbox integration',
            'key_prefix' => 'dpk_test',
            'abilities' => ['payments:read'],
            'is_active' => true,
            'expires_at' => null,
            'revoked_at' => null,
            'last_used_at' => null,
            'created_at' => '2026-10-16T00:00:00.000000Z',
            ...$overrides,
        ];
    }

    /** @return array<string, bool|string> */
    private function configurationPayload(array $overrides = []): array
    {
        return [
            'environment' => 'sandbox',
            'partner_id' => 'partner-sandbox-001',
            'merchant_id' => 'merchant-sandbox-secret',
            'private_key' => self::PrivateKey,
            'dana_webhook_public_key' => self::DanaWebhookPublicKey,
            'origin' => 'https://merchant.test',
            'channel_id' => 'channel-sandbox-001',
            'notification_url' => 'https://merchant.test/dana/notification',
            'callback_url' => 'https://merchant.test/dana/return',
            'mcc' => '7392',
            'enabled' => true,
            ...$overrides,
        ];
    }

    private function readinessUrl(int $merchantId): string
    {
        return "/api/v1/admin/dana-gateway-merchants/{$merchantId}/readiness";
    }

    private function configurationUrl(int $merchantId): string
    {
        return "/api/v1/admin/dana-gateway-merchants/{$merchantId}/dana-configuration";
    }

    private function storedConfigurationUrl(int $merchantId): string
    {
        return "/api/v1/admin/dana-gateway-merchants/{$merchantId}/dana-configuration";
    }

    private function apiKeysUrl(int $merchantId): string
    {
        return "/api/v1/admin/dana-gateway-merchants/{$merchantId}/api-keys";
    }

    private function apiKeyUrl(int $merchantId, int $apiKeyId): string
    {
        return $this->apiKeysUrl($merchantId)."/{$apiKeyId}";
    }

    private function actingAdmin(): self
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['admin:access']);

        return $this;
    }
}
