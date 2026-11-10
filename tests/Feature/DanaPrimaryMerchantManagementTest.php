<?php

namespace Tests\Feature;

use App\Models\DanaEnvironmentSetting;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Services\DanaEnvironmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class DanaPrimaryMerchantManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.dana_gateway', [
            'environment' => 'sandbox',
            'base_urls' => [
                'sandbox' => 'https://gateway-sandbox.test',
                'production' => 'https://gateway-prod.test',
            ],
            'timeout' => 8,
            'connect_timeout' => 3,
            'merchant_api_keys' => [
                'sandbox' => [
                    'udev-central' => 'key-central-sandbox',
                    'MRC-0002' => 'key-mrc0002-sandbox',
                ],
                'production' => [
                    'udev-central' => 'key-central-prod',
                    'MRC-0002' => 'key-mrc0002-prod',
                ],
            ],
            'merchants' => [
                'sandbox' => [
                    'udev-central' => ['name' => 'UDEV Central Sandbox', 'is_active' => true],
                    'MRC-0002' => ['name' => 'UDEV-SANBOX-BILL', 'is_active' => true],
                    'UNREADY-MERCHANT' => ['name' => 'Unready Merchant', 'is_active' => false],
                ],
                'production' => [
                    'udev-central' => ['name' => 'UDEV Central Prod', 'is_active' => true],
                    'MRC-0002' => ['name' => 'UDEV-PROD-BILL', 'is_active' => true],
                ],
            ],
        ]);

        config()->set('services.udev_billing.subscription_dana_sandbox_merchant_code', 'udev-central');
        config()->set('services.udev_billing.subscription_dana_production_merchant_code', 'udev-central');
        config()->set('services.dana_merchant_provisioning.base_url', 'https://dana-payment.test');
        config()->set('services.dana_merchant_provisioning.service_token', 'test-service-token');
    }

    public function test_set_primary_merchant_requires_admin_authorization(): void
    {
        $regularUser = User::factory()->create(['is_admin' => false]);
        Sanctum::actingAs($regularUser, ['*']);

        $this->postJson('/api/v1/admin/dana-gateway-merchants/environment/set-primary-merchant', [
            'merchant_code' => 'MRC-0002',
        ])->assertForbidden();
    }

    public function test_set_primary_merchant_rejects_unconfigured_or_unready_candidate(): void
    {
        // Candidate not configured in catalog
        $this->actingAdmin()
            ->postJson('/api/v1/admin/dana-gateway-merchants/environment/set-primary-merchant', [
                'merchant_code' => 'NON-EXISTENT',
            ])->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors('merchant_code');

        // Candidate inactive
        $this->actingAdmin()
            ->postJson('/api/v1/admin/dana-gateway-merchants/environment/set-primary-merchant', [
                'merchant_code' => 'UNREADY-MERCHANT',
            ])->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors('merchant_code');
    }

    public function test_set_primary_merchant_succeeds_for_ready_candidate_mrc_0002(): void
    {
        $this->actingAdmin()
            ->postJson('/api/v1/admin/dana-gateway-merchants/environment/set-primary-merchant', [
                'merchant_code' => 'MRC-0002',
                'notes' => 'Migrasi dari udev-central ke MRC-0002',
            ])->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.primary_merchant_code', 'MRC-0002');

        $this->assertSame('MRC-0002', DanaEnvironmentSetting::getPrimaryMerchantCode());
    }

    public function test_set_primary_merchant_by_id_endpoint_works(): void
    {
        $this->mockProvisioningClient();

        $this->actingAdmin()
            ->postJson('/api/v1/admin/dana-gateway-merchants/2/set-primary', [
                'notes' => 'Aktivasi MRC-0002 via merchant ID',
            ])->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.primary_merchant_code', 'MRC-0002');

        $this->assertSame('MRC-0002', DanaEnvironmentSetting::getPrimaryMerchantCode());
    }

    public function test_new_udev_subscription_checkout_uses_new_primary_merchant_mrc_0002(): void
    {
        // First activate MRC-0002 as primary merchant
        $admin = User::factory()->create(['is_admin' => true]);
        app(DanaEnvironmentService::class)->setPrimaryMerchant('MRC-0002', $admin);

        $invoice = Invoice::factory()->create([
            'amount' => '150000.00',
            'currency' => 'IDR',
            'due_at' => now()->addDay(),
        ]);

        $this->fakeCheckoutGateway('gateway-pay-mrc0002');

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
                'payment_method' => 'qris',
            ], ['Idempotency-Key' => 'test-idemp-mrc-001'])
            ->assertOk();

        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame('MRC-0002', $attempt->gateway_merchant_code);
        $this->assertSame('sandbox', $attempt->gateway_environment);
        $this->assertSame('udev_central', $attempt->metadata['billing_recipient'] ?? null);
    }

    public function test_historical_transactions_under_udev_central_remain_untouched(): void
    {
        // 1. Create historical transaction under udev-central
        $invoice1 = Invoice::factory()->create([
            'amount' => '75000.00',
            'currency' => 'IDR',
            'due_at' => now()->addDay(),
        ]);

        $this->fakeCheckoutGateway();

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$invoice1->id}/checkout", [
                'payment_method' => 'dana_wallet',
            ], ['Idempotency-Key' => 'test-idemp-hist-001'])
            ->assertOk();

        $histAttempt = PaymentAttempt::query()->where('invoice_id', $invoice1->id)->sole();
        $this->assertSame('udev-central', $histAttempt->gateway_merchant_code);

        // 2. Switch primary merchant to MRC-0002
        $admin = User::factory()->create(['is_admin' => true]);
        app(DanaEnvironmentService::class)->setPrimaryMerchant('MRC-0002', $admin);

        // 3. Verify historical attempt is completely untouched
        $refreshedHistAttempt = $histAttempt->fresh();
        $this->assertSame('udev-central', $refreshedHistAttempt->gateway_merchant_code);
        $this->assertSame('sandbox', $refreshedHistAttempt->gateway_environment);

        // 4. Create new transaction, which must use MRC-0002
        $invoice2 = Invoice::factory()->create([
            'amount' => '80000.00',
            'currency' => 'IDR',
            'due_at' => now()->addDay(),
        ]);

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$invoice2->id}/checkout", [
                'payment_method' => 'dana_wallet',
            ], ['Idempotency-Key' => 'test-idemp-new-002'])
            ->assertOk();

        $newAttempt = PaymentAttempt::query()->where('invoice_id', $invoice2->id)->sole();
        $this->assertSame('MRC-0002', $newAttempt->gateway_merchant_code);
    }

    public function test_dynamic_protection_protects_new_primary_merchant_and_permits_deactivating_udev_central(): void
    {
        // Switch primary merchant to MRC-0002
        $admin = User::factory()->create(['is_admin' => true]);
        app(DanaEnvironmentService::class)->setPrimaryMerchant('MRC-0002', $admin);

        // A. MRC-0002 is protected as central primary merchant
        $this->mockProvisioningClient();

        // Attempting to deactivate MRC-0002 -> blocked
        $this->actingAdmin()
            ->patchJson('/api/v1/admin/dana-gateway-merchants/2/status', ['is_active' => false])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Merchant MRC-0002 adalah merchant sistem pusat dan tidak dapat dinonaktifkan.');

        // Attempting to delete MRC-0002 -> blocked
        $this->actingAdmin()
            ->deleteJson('/api/v1/admin/dana-gateway-merchants/2')
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Merchant MRC-0002 adalah merchant sistem pusat dan tidak dapat dihapus.');

        // B. udev-central is no longer primary: can be deactivated safely
        $this->actingAdmin()
            ->patchJson('/api/v1/admin/dana-gateway-merchants/1/status', ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.merchant_code', 'udev-central')
            ->assertJsonPath('data.is_active', false);

        // C. udev-central cannot be deleted if it has payment history
        PaymentAttempt::factory()->create([
            'gateway_merchant_code' => 'udev-central',
            'status' => PaymentAttempt::StatusSucceeded,
        ]);

        $this->actingAdmin()
            ->deleteJson('/api/v1/admin/dana-gateway-merchants/1')
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Merchant udev-central memiliki riwayat 1 transaksi pembayaran dan tidak dapat dihapus. Silakan nonaktifkan merchant sebagai gantinya.');
    }

    public function test_rollback_restores_udev_central_as_primary_merchant(): void
    {
        // 1. Switch to MRC-0002
        $this->actingAdmin()
            ->postJson('/api/v1/admin/dana-gateway-merchants/environment/set-primary-merchant', [
                'merchant_code' => 'MRC-0002',
            ])->assertOk();
        $this->assertSame('MRC-0002', DanaEnvironmentSetting::getPrimaryMerchantCode());

        // 2. Rollback to udev-central
        $this->actingAdmin()
            ->postJson('/api/v1/admin/dana-gateway-merchants/environment/set-primary-merchant', [
                'merchant_code' => 'udev-central',
                'notes' => 'Rollback ke udev-central',
            ])->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.primary_merchant_code', 'udev-central');

        $this->assertSame('udev-central', DanaEnvironmentSetting::getPrimaryMerchantCode());
    }

    public function test_set_primary_merchant_by_id_fails_when_candidate_is_unready(): void
    {
        // Mock merchant 3 as unready merchant
        Http::fake([
            '*/api/v1/internal/merchants/3' => Http::response([
                'data' => [
                    'merchant_id' => 3,
                    'merchant_code' => 'UNREADY-MERCHANT',
                    'merchant_name' => 'Unready Merchant',
                    'environment' => 'sandbox',
                    'merchant_status' => 'active',
                    'provisioning_status' => 'provisioned',
                    'credential_status' => 'configured',
                    'dana_configuration_status' => 'available',
                    'provisioned_at' => now()->toIso8601String(),
                ],
            ]),
        ]);

        $response = $this->actingAdmin()
            ->postJson('/api/v1/admin/dana-gateway-merchants/3/set-primary');

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors('merchant_code');

        // Primary merchant must remain unchanged
        $this->assertSame('udev-central', DanaEnvironmentSetting::getPrimaryMerchantCode());
    }

    public function test_merchants_list_reflects_updated_primary_merchant_and_persists_after_reload(): void
    {
        $this->mockProvisioningClient();

        // 1. Initial list: udev-central is primary
        $initList = $this->actingAdmin()->getJson('/api/v1/admin/dana-gateway-merchants')->assertOk()->json('data');
        $this->assertTrue(collect($initList)->firstWhere('merchant_code', 'udev-central')['is_primary']);
        $this->assertFalse(collect($initList)->firstWhere('merchant_code', 'MRC-0002')['is_primary']);

        // 2. Set MRC-0002 as primary via set-primary by ID
        $this->actingAdmin()
            ->postJson('/api/v1/admin/dana-gateway-merchants/2/set-primary')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.primary_merchant_code', 'MRC-0002');

        // 3. Subsequent list (and simulated reload): MRC-0002 is primary, udev-central is not
        $reloadedList = $this->actingAdmin()->getJson('/api/v1/admin/dana-gateway-merchants')->assertOk()->json('data');
        $this->assertFalse(collect($reloadedList)->firstWhere('merchant_code', 'udev-central')['is_primary']);
        $this->assertTrue(collect($reloadedList)->firstWhere('merchant_code', 'MRC-0002')['is_primary']);
        $this->assertSame('MRC-0002', DanaEnvironmentSetting::getPrimaryMerchantCode());
    }

    private function actingAdmin(): self
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        return $this;
    }

    private function mockProvisioningClient(): void
    {
        Http::fake([
            '*/api/v1/internal/merchants*' => function (HttpRequest $request): mixed {
                $url = $request->url();
                if (preg_match('#/merchants/(\d+)#', $url, $m)) {
                    $id = (int) $m[1];
                    $code = $id === 2 ? 'MRC-0002' : 'udev-central';

                    if ($request->method() === 'PATCH') {
                        return Http::response([
                            'status' => 'success',
                            'data' => [
                                'merchant_id' => $id,
                                'merchant_code' => $code,
                                'merchant_name' => ucfirst($code),
                                'environment' => 'sandbox',
                                'is_active' => (bool) ($request['is_active'] ?? false),
                                'merchant_status' => $request['status'] ?? 'suspended',
                            ],
                        ]);
                    }

                    return Http::response([
                        'data' => [
                            'merchant_id' => $id,
                            'merchant_code' => $code,
                            'merchant_name' => ucfirst($code),
                            'environment' => 'sandbox',
                            'merchant_status' => 'active',
                            'provisioning_status' => 'provisioned',
                            'credential_status' => 'configured',
                            'dana_configuration_status' => 'available',
                            'provisioned_at' => now()->toIso8601String(),
                        ],
                    ]);
                }

                return Http::response([
                    'data' => [
                        [
                            'merchant_id' => 1,
                            'merchant_code' => 'udev-central',
                            'merchant_name' => 'UDEV Central',
                            'environment' => 'sandbox',
                            'merchant_status' => 'active',
                            'provisioning_status' => 'provisioned',
                            'credential_status' => 'configured',
                            'dana_configuration_status' => 'available',
                            'provisioned_at' => now()->toIso8601String(),
                        ],
                        [
                            'merchant_id' => 2,
                            'merchant_code' => 'MRC-0002',
                            'merchant_name' => 'UDEV-SANBOX-BILL',
                            'environment' => 'sandbox',
                            'merchant_status' => 'active',
                            'provisioning_status' => 'provisioned',
                            'credential_status' => 'configured',
                            'dana_configuration_status' => 'available',
                            'provisioned_at' => now()->toIso8601String(),
                        ],
                    ],
                    'meta' => [
                        'current_page' => 1,
                        'per_page' => 15,
                        'total' => 2,
                    ],
                ]);
            },
        ]);
    }

    private function fakeCheckoutGateway(?string $paymentId = null): void
    {
        Http::fake(function (HttpRequest $request) use ($paymentId): mixed {
            if ($request->method() === 'GET') {
                return Http::response(['data' => []]);
            }

            $id = $paymentId ?: 'pay-'.uniqid();

            return Http::response([
                'data' => [
                    'id' => $id,
                    'merchant_order_id' => $request['merchant_order_id'],
                    'amount_minor' => $request['amount_minor'],
                    'currency' => 'IDR',
                    'status' => 'pending',
                    'checkout_url' => "https://checkout.sandbox.test/pay/{$id}",
                    'expires_at' => now()->addMinutes(15)->toIso8601String(),
                    'metadata' => [
                        'udev_sandbox_payment_instruction' => [
                            'type' => 'qris_mpm',
                            'qr_content' => '00020101021226570011ID.DANA.WWW',
                        ],
                    ],
                ],
            ]);
        });
    }
}
