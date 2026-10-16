<?php

namespace Tests\Feature;

use App\Models\DanaMerchantApiKeyIssuance;
use App\Models\DanaMerchantProvisioningAudit;
use App\Models\User;
use App\Services\DanaMerchantApiKeyIssuanceService;
use App\Services\DanaMerchantApiKeySecretStore;
use App\Services\DanaMerchantProvisioningClient;
use App\Services\DanaMerchantProvisioningException;
use App\Services\DanaMerchantSecretStoreException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class DanaMerchantApiKeyIssuanceIdempotencyTest extends TestCase
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
        Http::preventStrayRequests();
    }

    public function test_retry_of_expired_issuance_returns_the_same_journal_but_a_new_action_creates_a_new_journal(): void
    {
        $merchant = DanaMerchantProvisioningAudit::factory()->create([
            'operation' => 'dana_merchant.provision',
            'gateway_merchant_id' => 101,
            'merchant_code' => 'udev-salon',
            'environment' => 'sandbox',
            'status' => 'succeeded',
        ]);
        $expired = DanaMerchantApiKeyIssuance::query()->create([
            'admin_user_id' => User::factory()->create()->id,
            'gateway_merchant_id' => 101,
            'merchant_code' => 'udev-salon',
            'environment' => 'sandbox',
            'idempotency_key' => 'frontend-action-expired-retry-001',
            'request_fingerprint' => $this->fingerprint(),
            'gateway_issuance_id' => 1,
            'gateway_api_key_id' => 3,
            'status' => 'expired',
        ]);
        $store = Mockery::mock(DanaMerchantApiKeySecretStore::class);
        $store->shouldReceive('store')->once()->andThrow(new DanaMerchantSecretStoreException('safe test storage failure'));
        $service = new DanaMerchantApiKeyIssuanceService(app(DanaMerchantProvisioningClient::class), $store);

        $replayed = $service->issue($merchant, 1, $this->attributes(), 'frontend-action-expired-retry-001');
        $this->assertSame($expired->id, $replayed->id);
        Http::assertNothingSent();

        Http::fake([
            'https://dana-gateway.test/api/v1/internal/merchants/101/api-keys' => Http::response([
                'data' => [
                    'issuance' => ['id' => 2, 'merchant_api_key_id' => 4, 'status' => 'pending'],
                    'api_key' => 'dummy-key-never-reported',
                ],
            ], 201),
        ]);
        try {
            $service->issue($merchant, 1, $this->attributes(), 'frontend-action-new-issuance-002');
            $this->fail('Kegagalan penyimpanan dummy harus diteruskan.');
        } catch (DanaMerchantSecretStoreException) {
            $this->assertDatabaseCount('dana_merchant_api_key_issuances', 2);
            $this->assertDatabaseHas('dana_merchant_api_key_issuances', [
                'idempotency_key' => 'frontend-action-new-issuance-002',
                'status' => 'cancel_pending',
            ]);
        }

        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://dana-gateway.test/api/v1/internal/merchants/101/api-keys'
            && $request->hasHeader('Idempotency-Key', 'frontend-action-new-issuance-002'));
    }

    public function test_new_action_is_blocked_while_another_issuance_requires_reconciliation(): void
    {
        $merchant = DanaMerchantProvisioningAudit::factory()->create([
            'operation' => 'dana_merchant.provision',
            'gateway_merchant_id' => 101,
            'merchant_code' => 'udev-salon',
            'environment' => 'sandbox',
            'status' => 'succeeded',
        ]);
        DanaMerchantApiKeyIssuance::query()->create([
            'admin_user_id' => User::factory()->create()->id,
            'gateway_merchant_id' => 101,
            'merchant_code' => 'udev-salon',
            'environment' => 'sandbox',
            'idempotency_key' => 'frontend-action-pending-001',
            'request_fingerprint' => $this->fingerprint(),
            'status' => 'confirmation_pending',
        ]);
        $service = new DanaMerchantApiKeyIssuanceService(
            app(DanaMerchantProvisioningClient::class),
            Mockery::mock(DanaMerchantApiKeySecretStore::class),
        );

        $this->expectException(DanaMerchantProvisioningException::class);
        $this->expectExceptionMessage('Masih ada issuance API key yang perlu direkonsiliasi.');
        $service->issue($merchant, 1, $this->attributes(), 'frontend-action-new-issuance-002');
    }

    private function attributes(): array
    {
        return [
            'environment' => 'sandbox',
            'name' => 'Sandbox checkout',
            'abilities' => ['payments:create'],
            'expires_at' => null,
        ];
    }

    private function fingerprint(): string
    {
        return hash_hmac('sha256', json_encode($this->attributes(), JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
