<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\ApplicationPaymentGateway;
use App\Models\DanaEnvironmentSetting;
use App\Models\DanaMerchantApiKeySecret;
use App\Models\DanaMerchantProvisioningAudit;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\DanaEnvironmentService;
use App\Services\DanaGatewayMerchantCatalog;
use App\Services\DanaGatewayMerchantResolver;
use App\Services\DanaMerchantApiKeySecretStore;
use App\Services\SubscriptionBillingMerchantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DanaDynamicDatabaseMerchantTest extends TestCase
{
    use RefreshDatabase;

    private const string SecretStoreKey = 'base64:AgvKd1migRF7GhtC1OMX3+fWegRebHe19GB2WBbS1xI=';

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Explicitly ensure .env merchant lists are EMPTY to prove complete independence from .env
        config()->set('services.dana_gateway.merchants', [
            'sandbox' => [],
            'production' => [],
        ]);
        config()->set('services.dana_gateway.merchant_api_keys', [
            'sandbox' => [],
            'production' => [],
        ]);

        // Base URLs are gateway endpoints (not per-merchant)
        config()->set('services.dana_gateway.base_urls', [
            'sandbox' => 'https://dana-gateway-sandbox.test',
            'production' => 'https://dana-gateway-prod.test',
        ]);

        // Master secret store key configured for testing
        config()->set('services.dana_merchant_secret_store', [
            'active_key_version' => 'v1',
            'keys' => ['v1' => self::SecretStoreKey],
        ]);
    }

    public function test_new_merchant_readiness_and_catalog_without_env_changes(): void
    {
        $merchant = $this->createProvisionedMerchant('MRC-DYNAMIC-01', 501);
        $this->storeEncryptedApiKey(501, 'MRC-DYNAMIC-01', 'sandbox', 'dpk_sandbox_key_abc123');

        $catalog = app(DanaGatewayMerchantCatalog::class);

        // Merchant is NOT in .env, but is fully ready via database
        $readiness = $catalog->readiness('MRC-DYNAMIC-01', 'sandbox');
        $this->assertTrue($readiness['merchant_configured']);
        $this->assertTrue($readiness['merchant_active']);
        $this->assertTrue($readiness['credential_configured']);
        $this->assertTrue($readiness['base_url_configured']);
        $this->assertTrue($readiness['ready']);

        // Catalog availableFor discovers the merchant from database
        $available = $catalog->availableFor('sandbox');
        $codes = collect($available)->pluck('code')->all();
        $this->assertContains('MRC-DYNAMIC-01', $codes);
    }

    public function test_resolver_resolves_and_decrypts_api_key_from_database_secret_store(): void
    {
        $this->createProvisionedMerchant('MRC-DYNAMIC-01', 501);
        $this->storeEncryptedApiKey(501, 'MRC-DYNAMIC-01', 'sandbox', 'dpk_sandbox_secret_plain_value');

        $resolver = app(DanaGatewayMerchantResolver::class);

        // Resolves without any .env entry
        $resolved = $resolver->resolve('MRC-DYNAMIC-01', 'sandbox');
        $this->assertSame('MRC-DYNAMIC-01', $resolved->code);
        $this->assertSame('sandbox', $resolved->environment);
        $this->assertSame('https://dana-gateway-sandbox.test', $resolved->baseUrl);
        $this->assertSame('dpk_sandbox_secret_plain_value', $resolved->apiKey);

        // Unregistered merchant throws ValidationException without silent fallback
        $this->expectException(ValidationException::class);
        $resolver->resolve('UNKNOWN-MERCHANT', 'sandbox');
    }

    public function test_set_primary_merchant_works_with_dynamic_database_merchant_without_env(): void
    {
        $this->createProvisionedMerchant('MRC-DYNAMIC-01', 501);
        $this->storeEncryptedApiKey(501, 'MRC-DYNAMIC-01', 'sandbox', 'dpk_sandbox_key_primary');

        $admin = User::factory()->create(['is_admin' => true]);
        $service = app(DanaEnvironmentService::class);

        $setting = $service->setPrimaryMerchant('MRC-DYNAMIC-01', $admin, 'Set dynamic merchant as primary');
        $this->assertSame('MRC-DYNAMIC-01', $setting->primary_merchant_code);

        $this->assertDatabaseHas('dana_environment_settings', [
            'gateway' => 'dana',
            'primary_merchant_code' => 'MRC-DYNAMIC-01',
        ]);

        $subscriptionResolver = app(SubscriptionBillingMerchantResolver::class);
        $resolvedCode = $subscriptionResolver->resolveNewCheckoutMerchant('sandbox');
        $this->assertSame('MRC-DYNAMIC-01', $resolvedCode);
    }

    public function test_subscription_invoice_checkout_with_dynamic_database_primary_merchant_without_env(): void
    {
        $this->createProvisionedMerchant('MRC-DYNAMIC-01', 501);
        $this->storeEncryptedApiKey(501, 'MRC-DYNAMIC-01', 'sandbox', 'dpk_sandbox_sub_key');

        $admin = User::factory()->create(['is_admin' => true]);
        app(DanaEnvironmentService::class)->setPrimaryMerchant('MRC-DYNAMIC-01', $admin);

        $invoice = Invoice::factory()->create([
            'amount' => '150000.00',
            'currency' => 'IDR',
            'status' => Invoice::StatusPending,
            'due_at' => now()->addDay(),
        ]);

        $this->fakeGateway('dana-pay-dyn-001', 150000);

        Sanctum::actingAs($admin, ['admin:access']);

        $response = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'qris',
        ], ['Idempotency-Key' => 'dyn-checkout-001']);

        $response->assertOk();

        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame('MRC-DYNAMIC-01', $attempt->gateway_merchant_code);
        $this->assertSame('sandbox', $attempt->gateway_environment);

        // Verify the outgoing request to DANA gateway used the decrypted dynamic API key
        Http::assertSent(function (HttpRequest $request): bool {
            return $request->header('Authorization') === ['Bearer dpk_sandbox_sub_key'];
        });
    }

    public function test_application_customer_invoice_checkout_with_dynamic_database_merchant_mapping_without_env(): void
    {
        $this->createProvisionedMerchant('MRC-APP-MERCHANT', 601);
        $this->storeEncryptedApiKey(601, 'MRC-APP-MERCHANT', 'sandbox', 'dpk_sandbox_app_key');

        $application = Application::factory()->create(['code' => 'APP-POS', 'name' => 'POS App']);
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        SubscriptionPlan::factory()->create(['application_id' => $application->id, 'price' => 50000]);

        ApplicationPaymentGateway::query()->create([
            'application_id' => $application->id,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'MRC-APP-MERCHANT',
            'is_active' => true,
        ]);

        \App\Models\DanaPaymentFeeSetting::query()->create([
            'gateway_merchant_code' => 'MRC-APP-MERCHANT',
            'application_id' => null,
            'payment_method' => 'dana_wallet',
            'environment' => 'sandbox',
            'fee_type' => 'dana',
            'fee_value' => 0.00,
            'fee_unit' => 'rupiah',
            'is_active' => true,
        ]);

        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'amount' => '50000.00',
            'currency' => 'IDR',
            'status' => Invoice::StatusPending,
            'due_at' => now()->addDay(),
        ]);

        $this->fakeGateway('dana-pay-app-001', 50000);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        $response = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'dana_wallet',
        ], ['Idempotency-Key' => 'dyn-app-checkout-001']);

        $response->assertOk();

        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame('MRC-APP-MERCHANT', $attempt->gateway_merchant_code);
        $this->assertSame('sandbox', $attempt->gateway_environment);

        Http::assertSent(function (HttpRequest $request): bool {
            return $request->header('Authorization') === ['Bearer dpk_sandbox_app_key'];
        });
    }

    private function fakeGateway(string $gatewayPaymentId, int $amountMinor = 150000): void
    {
        Http::fake(function (HttpRequest $request) use ($gatewayPaymentId, $amountMinor): mixed {
            if ($request->method() === 'GET') {
                return Http::response(['data' => []]);
            }

            $attempt = PaymentAttempt::query()->latest('attempt_number')->first();

            return Http::response([
                'data' => [
                    'id' => $gatewayPaymentId,
                    'merchant_order_id' => $attempt?->merchant_reference ?? 'REF-001',
                    'amount_minor' => $amountMinor,
                    'currency' => 'IDR',
                    'status' => 'pending',
                    'checkout_url' => "https://checkout.dana.id/{$gatewayPaymentId}",
                    'expires_at' => now()->addHour()->toISOString(),
                    'paid_at' => null,
                    'created_at' => now()->toISOString(),
                    'metadata' => [
                        'qr_content' => '00020101021226670016ID.DANA.WWW011893600918000000000152045411530336054061500005802ID5911UDEV CENTRAL6007JAKARTA6304ABCD',
                        'udev_sandbox_payment_instruction' => [
                            'method' => 'qris',
                            'label' => 'QRIS DANA',
                            'type' => 'qr',
                            'payment_code' => '00020101021226670016ID.DANA.WWW011893600918000000000152045411530336054061500005802ID5911UDEV CENTRAL6007JAKARTA6304ABCD',
                        ],
                    ],
                ],
            ], 201);
        });
    }

    public function test_dual_environment_sandbox_and_production_keys_resolved_independently(): void
    {
        $this->createProvisionedMerchant('MRC-DUAL-001', 701);
        $this->storeEncryptedApiKey(701, 'MRC-DUAL-001', 'sandbox', 'dpk_sandbox_dual_key');
        $this->storeEncryptedApiKey(701, 'MRC-DUAL-001', 'production', 'dpk_production_dual_key');

        $resolver = app(DanaGatewayMerchantResolver::class);

        $sandboxMerchant = $resolver->resolve('MRC-DUAL-001', 'sandbox');
        $this->assertSame('dpk_sandbox_dual_key', $sandboxMerchant->apiKey);
        $this->assertSame('https://dana-gateway-sandbox.test', $sandboxMerchant->baseUrl);

        $prodMerchant = $resolver->resolve('MRC-DUAL-001', 'production');
        $this->assertSame('dpk_production_dual_key', $prodMerchant->apiKey);
        $this->assertSame('https://dana-gateway-prod.test', $prodMerchant->baseUrl);
    }

    public function test_legacy_merchant_compatibility_during_transition(): void
    {
        // Merchant existing only defined in legacy config (like udev-central during transition)
        config()->set('services.dana_gateway.merchants.sandbox.udev-legacy', [
            'name' => 'UDEV Legacy Transition Merchant',
            'is_active' => true,
        ]);
        config()->set('services.dana_gateway.merchant_api_keys.sandbox.udev-legacy', 'dpk_legacy_transition_key');

        $catalog = app(DanaGatewayMerchantCatalog::class);
        $resolver = app(DanaGatewayMerchantResolver::class);

        $readiness = $catalog->readiness('udev-legacy', 'sandbox');
        $this->assertTrue($readiness['ready']);

        $resolved = $resolver->resolve('udev-legacy', 'sandbox');
        $this->assertSame('udev-legacy', $resolved->code);
        $this->assertSame('dpk_legacy_transition_key', $resolved->apiKey);
    }

    private function createProvisionedMerchant(string $code, int $gatewayMerchantId): DanaMerchantProvisioningAudit
    {
        $admin = User::factory()->create(['is_admin' => true]);

        return DanaMerchantProvisioningAudit::query()->create([
            'admin_user_id' => $admin->id,
            'operation' => 'dana_merchant.provision',
            'merchant_code' => $code,
            'gateway_merchant_id' => $gatewayMerchantId,
            'environment' => 'sandbox',
            'idempotency_fingerprint' => hash('sha256', "prov-{$code}"),
            'status' => 'succeeded',
            'safe_details' => [
                'merchant_id' => $gatewayMerchantId,
                'code' => $code,
                'name' => "Merchant {$code}",
            ],
        ]);
    }

    private function storeEncryptedApiKey(int $gatewayMerchantId, string $merchantCode, string $environment, string $plainApiKey): DanaMerchantApiKeySecret
    {
        $store = app(DanaMerchantApiKeySecretStore::class);

        return $store->store([
            'gateway_merchant_id' => $gatewayMerchantId,
            'gateway_api_key_id' => 900 + $gatewayMerchantId,
            'key_prefix' => substr($plainApiKey, 0, 10),
            'plaintext_api_key' => $plainApiKey,
            'environment' => $environment,
        ]);
    }
}
