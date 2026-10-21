<?php

namespace Tests\Feature;

use App\Models\DanaMerchantProvisioningAudit;
use App\Models\DanaSandboxResultContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DanaSandboxTesterApiTest extends TestCase
{
    use RefreshDatabase;

    private const string ServiceToken = 'test-sandbox-tester-service-token';

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

    public function test_tester_endpoints_require_authorized_admin_and_known_sandbox_merchant(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();

        $this->getJson($this->validationUrl($merchant->gateway_merchant_id))->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(), ['admin:access']);
        $this->getJson($this->validationUrl($merchant->gateway_merchant_id))->assertForbidden();

        $this->actingAdmin();
        $this->getJson($this->validationUrl(999999))->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_validation_only_returns_safe_configuration_metadata_and_audits_the_result(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        $privateKey = 'private-key-must-not-leave-gateway';
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/validation" => Http::response([
                'data' => $this->validation($merchant->merchant_code, $privateKey),
            ]),
        ]);
        $this->actingAdmin();

        $this->getJson($this->validationUrl($merchant->gateway_merchant_id))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.format_valid', true)
            ->assertJsonPath('data.private_key.format_valid', true)
            ->assertJsonPath('data.dana_webhook_public_key.format_valid', true)
            ->assertJsonMissingPath('data.private_key.value')
            ->assertDontSee($privateKey);

        $this->assertDatabaseHas('dana_merchant_provisioning_audits', [
            'gateway_merchant_id' => $merchant->gateway_merchant_id,
            'operation' => 'dana_sandbox_tester.configuration_validated',
            'status' => 'succeeded',
        ]);
    }

    public function test_creating_transaction_binds_a_hashed_result_context_before_returning_checkout(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        $idempotencyKey = 'frontend-sandbox-tester-action-001';
        $gatewayContext = null;
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/transactions" => function (Request $request) use ($merchant, &$gatewayContext) {
                $gatewayContext = $request->data()['return_context'] ?? null;

                return Http::response(['data' => $this->transaction($merchant->merchant_code, $gatewayContext)], 201);
            },
        ]);
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        $response = $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson($this->transactionUrl($merchant->gateway_merchant_id), ['amount_minor' => 10_000])
            ->assertCreated()
            ->assertJsonPath('data.transaction.status', 'pending')
            ->assertJsonPath('data.transaction.checkout_available', true)
            ->assertJsonPath('data.transaction.checkout_url', 'https://checkout.sandbox.dana.id/session/udev-test')
            ->assertJsonMissingPath('data.transaction.private_key');

        $resultContext = $response->json('data.result_context');
        $this->assertIsString($gatewayContext);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $gatewayContext);
        $this->assertSame($gatewayContext, $resultContext);
        $this->assertDatabaseHas('dana_sandbox_result_contexts', [
            'admin_user_id' => $admin->id,
            'gateway_merchant_id' => $merchant->gateway_merchant_id,
            'gateway_payment_id' => '01K7A2M3N4P5Q6R7S8T9V0W1X2',
            'context_hash' => hash('sha256', $gatewayContext),
        ]);

        Http::assertSent(function (Request $request) use ($idempotencyKey): bool {
            return $request->method() === 'POST'
                && $request->header('Idempotency-Key') === [$idempotencyKey]
                && $request->data()['amount_minor'] === 10_000
                && is_string($request->data()['return_context'] ?? null)
                && $request->header('Authorization') === ['Bearer '.self::ServiceToken];
        });
        $this->assertDatabaseHas('dana_merchant_provisioning_audits', [
            'operation' => 'dana_sandbox_tester.transaction_created',
            'status' => 'succeeded',
        ]);
    }

    public function test_result_endpoint_requires_the_creating_admin_valid_context_and_matching_merchant_payment(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        $paymentId = '01K7A2M3N4P5Q6R7S8T9V0W1X2';
        $context = str_repeat('A', 43);
        $admin = User::factory()->create(['is_admin' => true]);
        DanaSandboxResultContext::query()->create([
            'admin_user_id' => $admin->id,
            'gateway_merchant_id' => $merchant->gateway_merchant_id,
            'gateway_payment_id' => $paymentId,
            'context_hash' => hash('sha256', $context),
            'idempotency_fingerprint' => hash('sha256', 'result-endpoint-test'),
            'expires_at' => now()->addMinutes(20),
        ]);
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/transactions/{$paymentId}/status" => Http::response([
                'data' => $this->transaction($merchant->merchant_code),
            ]),
        ]);

        Sanctum::actingAs($admin, ['admin:access']);
        $this->getJson("/api/v1/admin/dana-gateway-merchants/sandbox-payment-results/{$context}")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.transaction.id', $paymentId)
            ->assertJsonPath('data.transaction.status', 'pending');

        $this->getJson("/api/v1/admin/dana-gateway-merchants/{$merchant->gateway_merchant_id}/sandbox-tester/transactions/{$paymentId}/result")
            ->assertOk()
            ->assertJsonPath('data.transaction.id', $paymentId);

        Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['admin:access']);
        $this->getJson("/api/v1/admin/dana-gateway-merchants/sandbox-payment-results/{$context}")->assertNotFound();
        $this->getJson("/api/v1/admin/dana-gateway-merchants/{$merchant->gateway_merchant_id}/sandbox-tester/transactions/{$paymentId}/result")->assertForbidden();
    }

    public function test_tester_history_is_sanitized_before_it_reaches_the_admin_ui(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/transactions*" => Http::response([
                'data' => [[
                    'id' => 10,
                    'merchant_code' => $merchant->merchant_code,
                    'environment' => 'sandbox',
                    'operation' => 'transaction_created',
                    'status' => 'failed',
                    'message' => 'Checkout Sandbox belum dapat dibuat.',
                    'payment_id' => null,
                    'payment_status' => null,
                    'created_at' => '2026-10-17T10:00:00+07:00',
                    'private_key' => 'must-not-reach-ui',
                ]],
                'meta' => ['current_page' => 1, 'per_page' => 15, 'total' => 1, 'last_page' => 1],
            ]),
        ]);
        $this->actingAdmin();

        $this->getJson($this->transactionUrl($merchant->gateway_merchant_id))
            ->assertOk()
            ->assertJsonPath('data.0.message', 'Checkout Sandbox belum dapat dibuat.')
            ->assertJsonMissingPath('data.0.private_key')
            ->assertDontSee('must-not-reach-ui');
    }

    /** @return array<string, mixed> */
    private function validation(string $merchantCode, string $privateKey): array
    {
        return [
            'merchant_code' => $merchantCode,
            'environment' => 'sandbox',
            'configuration_exists' => true,
            'enabled' => true,
            'required_fields' => [
                'merchant_id' => true,
                'partner_id' => true,
                'origin' => true,
                'channel_id' => true,
                'notification_url' => true,
                'pay_return_url' => true,
                'mcc' => true,
            ],
            'private_key' => ['configured' => true, 'format_valid' => true, 'value' => $privateKey],
            'dana_webhook_public_key' => ['configured' => true, 'format_valid' => true],
            'format_valid' => true,
            'authentication' => [
                'status' => 'not_attempted',
                'message' => 'Autentikasi DANA hanya dapat dikonfirmasi dari respons Create Order Sandbox yang valid.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function transaction(string $merchantCode, ?string $returnContext = null): array
    {
        return array_filter([
            'merchant_code' => $merchantCode,
            'environment' => 'sandbox',
            'authentication' => [
                'status' => 'confirmed_by_checkout_response',
                'message' => 'Create Order Sandbox diterima dengan respons checkout yang valid.',
            ],
            'transaction' => [
                'id' => '01K7A2M3N4P5Q6R7S8T9V0W1X2',
                'merchant_order_id' => 'UDEV-SANDBOX-001',
                'amount_minor' => 10_000,
                'currency' => 'IDR',
                'status' => 'pending',
                'checkout_url' => 'https://checkout.sandbox.dana.id/session/udev-test',
                'checkout_available' => true,
                'created_at' => '2026-10-17T10:00:00+07:00',
                'updated_at' => '2026-10-17T10:00:00+07:00',
            ],
            'return_context' => $returnContext,
        ], static fn (mixed $value): bool => $value !== null);
    }

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

    private function validationUrl(int $merchantId): string
    {
        return "/api/v1/admin/dana-gateway-merchants/{$merchantId}/sandbox-tester/validation";
    }

    private function transactionUrl(int $merchantId): string
    {
        return "/api/v1/admin/dana-gateway-merchants/{$merchantId}/sandbox-tester/transactions";
    }

    private function actingAdmin(): self
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['admin:access']);

        return $this;
    }
}
