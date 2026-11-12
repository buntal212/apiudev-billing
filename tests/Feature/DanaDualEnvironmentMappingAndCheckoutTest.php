<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\ApplicationPaymentGateway;
use App\Models\DanaEnvironmentSetting;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DanaDualEnvironmentMappingAndCheckoutTest extends TestCase
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
                    'larissama' => 'key-larissama-sandbox',
                ],
                'production' => [
                    'udev-central' => 'key-central-prod',
                    'larissama' => 'key-larissama-prod',
                ],
            ],
            'merchants' => [
                'sandbox' => [
                    'udev-central' => ['name' => 'UDEV Central Sandbox', 'is_active' => true],
                    'larissama' => ['name' => 'Larissama Sandbox', 'is_active' => true],
                ],
                'production' => [
                    'udev-central' => ['name' => 'UDEV Central Prod', 'is_active' => true],
                    'larissama' => ['name' => 'Larissama Prod', 'is_active' => true],
                ],
            ],
        ]);

        config()->set('services.udev_billing.subscription_dana_sandbox_merchant_code', 'udev-central');
        config()->set('services.udev_billing.subscription_dana_production_merchant_code', 'udev-central');
    }

    public function test_admin_can_create_separate_dana_sandbox_and_production_mappings_for_single_application(): void
    {
        $app = Application::factory()->create(['name' => 'Larissama POS', 'code' => 'larissama']);
        $this->actingAdmin();

        // 1. Create Sandbox mapping
        $sbxRes = $this->postJson('/api/v1/admin/payment-gateways', [
            'application_id' => $app->id,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'larissama',
            'is_active' => true,
        ])->assertCreated()
            ->assertJsonPath('data.application_id', $app->id)
            ->assertJsonPath('data.provider', 'dana')
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.gateway_merchant_code', 'larissama');

        // 2. Create Production mapping for the exact same application
        $prodRes = $this->postJson('/api/v1/admin/payment-gateways', [
            'application_id' => $app->id,
            'provider' => 'dana',
            'environment' => 'production',
            'gateway_merchant_code' => 'larissama',
            'is_active' => true,
        ])->assertCreated()
            ->assertJsonPath('data.application_id', $app->id)
            ->assertJsonPath('data.provider', 'dana')
            ->assertJsonPath('data.environment', 'production')
            ->assertJsonPath('data.gateway_merchant_code', 'larissama');

        $this->assertNotSame($sbxRes->json('data.id'), $prodRes->json('data.id'));
        $this->assertDatabaseCount('application_payment_gateways', 2);

        // 3. GET listing returns both rows
        $listRes = $this->getJson("/api/v1/admin/payment-gateways?application_id={$app->id}&provider=dana")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $envs = collect($listRes->json('data'))->pluck('environment')->all();
        $this->assertEqualsCanonicalizing(['sandbox', 'production'], $envs);
    }

    public function test_duplicate_mapping_same_application_provider_environment_is_rejected(): void
    {
        $app = Application::factory()->create();
        $this->actingAdmin();

        $this->postJson('/api/v1/admin/payment-gateways', [
            'application_id' => $app->id,
            'provider' => 'dana',
            'environment' => 'production',
            'gateway_merchant_code' => 'larissama',
            'is_active' => true,
        ])->assertCreated();

        // Re-adding same environment for same app + provider must be rejected with 422
        $this->postJson('/api/v1/admin/payment-gateways', [
            'application_id' => $app->id,
            'provider' => 'dana',
            'environment' => 'production',
            'gateway_merchant_code' => 'larissama',
            'is_active' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['application_id'])
            ->assertJsonPath('errors.application_id.0', 'Aplikasi sudah memiliki mapping untuk provider dan environment ini.');
    }

    public function test_update_mapping_environment_to_duplicate_environment_is_rejected(): void
    {
        $app = Application::factory()->create();
        $this->actingAdmin();

        $sbx = ApplicationPaymentGateway::query()->create([
            'application_id' => $app->id,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'larissama',
            'is_active' => true,
        ]);

        $prod = ApplicationPaymentGateway::query()->create([
            'application_id' => $app->id,
            'provider' => 'dana',
            'environment' => 'production',
            'gateway_merchant_code' => 'larissama',
            'is_active' => true,
        ]);

        // Attempting to change production mapping's environment to sandbox must fail with 422
        $this->patchJson("/api/v1/admin/payment-gateways/{$prod->id}", [
            'environment' => 'sandbox',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['environment'])
            ->assertJsonPath('errors.environment.0', 'Aplikasi sudah memiliki mapping untuk provider dan environment ini.');
    }

    public function test_update_merchant_code_or_active_status_does_not_mutate_other_environment(): void
    {
        $app = Application::factory()->create();
        $this->actingAdmin();

        $sbx = ApplicationPaymentGateway::query()->create([
            'application_id' => $app->id,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'larissama',
            'is_active' => true,
        ]);

        $prod = ApplicationPaymentGateway::query()->create([
            'application_id' => $app->id,
            'provider' => 'dana',
            'environment' => 'production',
            'gateway_merchant_code' => 'larissama',
            'is_active' => true,
        ]);

        // Disable sandbox mapping
        $this->patchJson("/api/v1/admin/payment-gateways/{$sbx->id}", [
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', false);

        // Verify production remains active
        $this->assertTrue(ApplicationPaymentGateway::query()->findOrFail($prod->id)->is_active);
        $this->assertFalse(ApplicationPaymentGateway::query()->findOrFail($sbx->id)->is_active);
    }

    public function test_checkout_selects_sandbox_mapping_when_environment_is_sandbox_without_fallback(): void
    {
        $app = Application::factory()->create(['code' => 'APP-LARIS', 'name' => 'Larissama App']);
        $customer = ApplicationCustomer::factory()->create(['application_id' => $app->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $app->id, 'price' => 50000]);

        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '50000.00',
            'currency' => 'IDR',
            'due_at' => now()->addDay(),
        ]);

        // Dual mapping exists
        ApplicationPaymentGateway::query()->create([
            'application_id' => $app->id,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'larissama',
            'is_active' => true,
        ]);
        ApplicationPaymentGateway::query()->create([
            'application_id' => $app->id,
            'provider' => 'dana',
            'environment' => 'production',
            'gateway_merchant_code' => 'larissama',
            'is_active' => true,
        ]);

        // Sandbox environment active
        DanaEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'dana'],
            ['active_environment' => 'sandbox']
        );

        $this->fakeGateway('pay-sbx-01', 'sandbox');

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
                'payment_method' => 'qris',
            ], ['Idempotency-Key' => 'idemp-chk-sbx-01'])
            ->assertOk();

        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame('sandbox', $attempt->gateway_environment);
        $this->assertSame('larissama', $attempt->gateway_merchant_code);
    }

    public function test_checkout_selects_production_mapping_when_environment_is_production_without_fallback(): void
    {
        $app = Application::factory()->create(['code' => 'APP-LARIS', 'name' => 'Larissama App']);
        $customer = ApplicationCustomer::factory()->create(['application_id' => $app->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $app->id, 'price' => 50000]);

        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '50000.00',
            'currency' => 'IDR',
            'due_at' => now()->addDay(),
        ]);

        // Dual mapping exists
        ApplicationPaymentGateway::query()->create([
            'application_id' => $app->id,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'larissama',
            'is_active' => true,
        ]);
        ApplicationPaymentGateway::query()->create([
            'application_id' => $app->id,
            'provider' => 'dana',
            'environment' => 'production',
            'gateway_merchant_code' => 'larissama',
            'is_active' => true,
        ]);

        // Production environment active
        DanaEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'dana'],
            ['active_environment' => 'production', 'is_production_ready' => true]
        );

        $this->fakeGateway('pay-prod-01', 'production');

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
                'payment_method' => 'qris',
            ], ['Idempotency-Key' => 'idemp-chk-prod-01'])
            ->assertOk();

        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame('production', $attempt->gateway_environment);
        $this->assertSame('larissama', $attempt->gateway_merchant_code);
    }

    public function test_checkout_rejects_with_422_when_production_environment_active_but_app_only_has_sandbox_mapping(): void
    {
        $app = Application::factory()->create(['code' => 'APP-DEV', 'name' => 'Dev App']);
        $customer = ApplicationCustomer::factory()->create(['application_id' => $app->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $app->id, 'price' => 25000]);

        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '25000.00',
            'currency' => 'IDR',
            'due_at' => now()->addDay(),
        ]);

        // ONLY Sandbox mapping configured
        ApplicationPaymentGateway::query()->create([
            'application_id' => $app->id,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'larissama',
            'is_active' => true,
        ]);

        // But gateway active environment is PRODUCTION
        DanaEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'dana'],
            ['active_environment' => 'production', 'is_production_ready' => true]
        );

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
                'payment_method' => 'qris',
            ], ['Idempotency-Key' => 'idemp-chk-fail-prod'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['invoice'])
            ->assertJsonPath('errors.invoice.0', 'Merchant DANA Production untuk aplikasi ini belum siap digunakan. Transaksi live tidak dapat dialihkan ke Sandbox.');
    }

    public function test_checkout_rejects_with_422_when_sandbox_environment_active_but_app_only_has_production_mapping(): void
    {
        $app = Application::factory()->create(['code' => 'APP-PROD-ONLY', 'name' => 'Prod Only App']);
        $customer = ApplicationCustomer::factory()->create(['application_id' => $app->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $app->id, 'price' => 25000]);

        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '25000.00',
            'currency' => 'IDR',
            'due_at' => now()->addDay(),
        ]);

        // ONLY Production mapping configured
        ApplicationPaymentGateway::query()->create([
            'application_id' => $app->id,
            'provider' => 'dana',
            'environment' => 'production',
            'gateway_merchant_code' => 'larissama',
            'is_active' => true,
        ]);

        // Gateway active environment is SANDBOX
        DanaEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'dana'],
            ['active_environment' => 'sandbox']
        );

        $this->actingAdmin()
            ->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
                'payment_method' => 'qris',
            ], ['Idempotency-Key' => 'idemp-chk-fail-sbx'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['invoice'])
            ->assertJsonPath('errors.invoice.0', 'Merchant DANA Sandbox aplikasi belum siap digunakan.');
    }

    private function actingAdmin(): self
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        return $this;
    }

    private function fakeGateway(string $paymentId = 'pay-001', string $env = 'sandbox'): void
    {
        Http::fake(function (HttpRequest $request) use ($paymentId, $env): mixed {
            if ($request->method() === 'GET') {
                return Http::response(['data' => []]);
            }

            return Http::response([
                'data' => [
                    'id' => $paymentId,
                    'merchant_order_id' => $request['merchant_order_id'],
                    'amount_minor' => $request['amount_minor'],
                    'currency' => 'IDR',
                    'status' => 'pending',
                    'checkout_url' => "https://checkout.{$env}.test/pay/{$paymentId}",
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
