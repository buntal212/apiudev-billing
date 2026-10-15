<?php

namespace Tests\Feature;

use App\Models\DanaMerchantProvisioningAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DanaGatewayMerchantProvisioningApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.dana_merchant_provisioning', [
            'base_url' => 'https://dana-gateway.test',
            'service_token' => 'test-service-token',
            'timeout' => 8,
            'connect_timeout' => 3,
        ]);
    }

    public function test_unauthenticated_non_admin_and_missing_ability_requests_are_rejected(): void
    {
        $this->postJson('/api/v1/admin/dana-gateway-merchants', $this->payload())
            ->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(), ['admin:access']);
        $this->postJson('/api/v1/admin/dana-gateway-merchants', $this->payload())
            ->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
        $this->postJson('/api/v1/admin/dana-gateway-merchants', $this->payload())
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_admin_request_is_validated_and_cannot_supply_gateway_credentials_or_idempotency_key(): void
    {
        $this->actingAdmin();

        $this->postJson('/api/v1/admin/dana-gateway-merchants', [
            'code' => 'kode dengan spasi',
            'name' => '',
            'email' => 'bukan-email',
            'environment' => 'development',
            'service_token' => 'frontend-token',
            'idempotency_key' => 'frontend-key',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'name', 'email', 'environment', 'service_token', 'idempotency_key']);

        Http::assertNothingSent();
    }

    public function test_admin_can_provision_a_merchant_with_server_side_credential_and_a_stable_idempotency_key(): void
    {
        Http::fake([
            'https://dana-gateway.test/api/v1/internal/merchants' => Http::response(['data' => $this->merchant()], 201),
        ]);
        $this->actingAdmin();

        $response = $this->postJson('/api/v1/admin/dana-gateway-merchants', $this->payload());

        $response
            ->assertCreated()
            ->assertJsonPath('data.merchant_code', 'udev-salon')
            ->assertJsonPath('data.credential_status', 'bootstrap_required')
            ->assertJsonPath('data.dana_configuration_status', 'required')
            ->assertJsonMissingPath('data.api_key')
            ->assertDontSee('test-service-token');

        Http::assertSent(function (HttpRequest $request): bool {
            $this->assertSame('POST', $request->method());
            $this->assertSame(['Bearer test-service-token'], $request->header('Authorization'));
            $this->assertSame('udev-salon', $request->data()['code']);
            $this->assertMatchesRegularExpression('/^udev-merchant-[a-f0-9]{64}$/', $request->data()['idempotency_key']);

            return true;
        });

        $audit = DanaMerchantProvisioningAudit::query()->sole();
        $this->assertSame('succeeded', $audit->status);
        $this->assertSame('udev-salon', $audit->merchant_code);
        $this->assertSame(101, $audit->gateway_merchant_id);
        $this->assertArrayNotHasKey('email', $audit->safe_details);
        $this->assertDatabaseCount('application_payment_gateways', 0);
    }

    public function test_repeated_payload_uses_the_same_idempotency_key_and_never_updates_the_catalog(): void
    {
        $idempotencyKeys = [];
        Http::fake(function (HttpRequest $request) use (&$idempotencyKeys): mixed {
            $idempotencyKeys[] = $request->data()['idempotency_key'];

            return Http::response(['data' => $this->merchant()], count($idempotencyKeys) === 1 ? 201 : 200);
        });
        $this->actingAdmin();

        $this->postJson('/api/v1/admin/dana-gateway-merchants', $this->payload())->assertCreated();
        $this->postJson('/api/v1/admin/dana-gateway-merchants', $this->payload())->assertOk();

        $this->assertCount(2, $idempotencyKeys);
        $this->assertSame($idempotencyKeys[0], $idempotencyKeys[1]);
        $this->getJson('/api/v1/admin/payment-gateways/merchants?environment=sandbox')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('application_payment_gateways', 0);
    }

    public function test_admin_can_read_gateway_merchant_list_and_detail_without_disclosing_gateway_urls(): void
    {
        Http::fake([
            'https://dana-gateway.test/api/v1/internal/merchants/101' => Http::response(['data' => $this->merchant()]),
            'https://dana-gateway.test/api/v1/internal/merchants*' => Http::response([
                'data' => [$this->merchant()],
                'meta' => ['current_page' => 1, 'per_page' => 20, 'total' => 1],
                'links' => ['first' => 'https://dana-gateway.test/api/v1/internal/merchants?page=1'],
            ]),
        ]);
        $this->actingAdmin();

        $this->getJson('/api/v1/admin/dana-gateway-merchants?environment=sandbox')
            ->assertOk()
            ->assertJsonPath('data.0.merchant_id', 101)
            ->assertJsonPath('meta.total', 1)
            ->assertDontSee('dana-gateway.test');

        $this->getJson('/api/v1/admin/dana-gateway-merchants/101')
            ->assertOk()
            ->assertJsonPath('data.merchant_name', 'UDEV Salon')
            ->assertJsonMissingPath('data.contact_email');
    }

    public function test_gateway_errors_are_safely_mapped_and_audited(): void
    {
        $cases = [
            401 => 502,
            403 => 502,
            409 => 409,
            422 => 422,
            429 => 503,
            500 => 503,
        ];

        Http::fake(function (HttpRequest $request): mixed {
            $gatewayStatus = (int) (string) str($request->data()['code'])->afterLast('-');

            return Http::response(['message' => 'respons sensitif gateway'], $gatewayStatus);
        });

        foreach ($cases as $gatewayStatus => $expectedStatus) {
            $this->actingAdmin();

            $this->postJson('/api/v1/admin/dana-gateway-merchants', $this->payload(['code' => "udev-salon-{$gatewayStatus}"]))
                ->assertStatus($expectedStatus)
                ->assertDontSee('respons sensitif gateway')
                ->assertDontSee('test-service-token');
        }

        $this->assertDatabaseCount('dana_merchant_provisioning_audits', count($cases));
        $this->assertDatabaseHas('dana_merchant_provisioning_audits', ['status' => 'failed', 'gateway_status' => 429]);
    }

    public function test_network_timeout_is_reported_without_sensitive_details_and_is_audited(): void
    {
        Http::fake(fn (): never => throw new ConnectionException('timeout with test-service-token'));
        $this->actingAdmin();

        $this->postJson('/api/v1/admin/dana-gateway-merchants', $this->payload())
            ->assertStatus(503)
            ->assertDontSee('test-service-token')
            ->assertDontSee('timeout');

        $this->assertDatabaseHas('dana_merchant_provisioning_audits', [
            'status' => 'failed',
            'gateway_status' => null,
        ]);
    }

    public function test_http_is_never_sent_when_the_gateway_url_is_not_https(): void
    {
        config()->set('services.dana_merchant_provisioning.base_url', 'http://dana-gateway.test');
        $this->actingAdmin();

        $this->postJson('/api/v1/admin/dana-gateway-merchants', $this->payload())
            ->assertStatus(503);

        Http::assertNothingSent();
    }

    public function test_provisioning_endpoints_are_rate_limited(): void
    {
        Http::fake([
            'https://dana-gateway.test/api/v1/internal/merchants*' => Http::response(['data' => []]),
        ]);
        $this->actingAdmin();

        foreach (range(1, 10) as $attempt) {
            $this->getJson('/api/v1/admin/dana-gateway-merchants')->assertOk();
        }

        $this->getJson('/api/v1/admin/dana-gateway-merchants')
            ->assertTooManyRequests();
    }

    /** @return array{code: string, name: string, email: string, environment: string} */
    private function payload(array $overrides = []): array
    {
        return [
            'code' => 'udev-salon',
            'name' => 'UDEV Salon',
            'email' => 'billing@udev.test',
            'environment' => 'sandbox',
            ...$overrides,
        ];
    }

    /** @return array<string, int|string|null> */
    private function merchant(): array
    {
        return [
            'merchant_id' => 101,
            'merchant_code' => 'udev-salon',
            'merchant_name' => 'UDEV Salon',
            'environment' => 'sandbox',
            'merchant_status' => 'active',
            'provisioning_status' => 'provisioned',
            'credential_status' => 'bootstrap_required',
            'dana_configuration_status' => 'required',
            'provisioned_at' => now()->toISOString(),
        ];
    }

    private function actingAdmin(): self
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['admin:access']);

        return $this;
    }
}
