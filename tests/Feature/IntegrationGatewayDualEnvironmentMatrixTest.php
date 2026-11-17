<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\ApplicationPaymentGateway;
use App\Models\DanaEnvironmentSetting;
use App\Models\MidtransEnvironmentSetting;
use App\Models\SubscriptionPlan;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationGatewayDualEnvironmentMatrixTest extends TestCase
{
    use RefreshDatabase;

    private Application $testApplication;
    private string $token;
    private ApplicationCustomer $customer;
    private SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-17 10:00:00');

        [$this->testApplication, $this->token] = $this->createApplicationWithToken('TestApp');

        $this->customer = ApplicationCustomer::factory()->create([
            'application_id' => $this->testApplication->id,
            'external_customer_id' => 'CUST-MATRIX-01',
        ]);

        $this->plan = SubscriptionPlan::factory()->create([
            'application_id' => $this->testApplication->id,
            'code' => 'PLAN-MATRIX-01',
            'price' => '150000.00',
            'duration_days' => 30,
            'is_active' => true,
        ]);
    }

    public function test_preview_returns_dana_production_when_midtrans_is_sandbox(): void
    {
        $this->setupGateways();

        MidtransEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'midtrans'],
            ['active_environment' => 'sandbox']
        );
        DanaEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'dana'],
            [
                'active_environment' => 'production',
                'primary_merchant_code' => 'test-dana-prod',
                'is_production_ready' => true,
            ]
        );

        $response = $this->withToken($this->token)
            ->postJson("/api/v1/integrations/customers/{$this->customer->external_customer_id}/invoice-preview", [
                'subscription_plan_code' => $this->plan->code,
                'period_count' => 1,
            ]);

        $response->assertOk()
            ->assertJsonCount(2, 'data.available_gateways')
            ->assertJsonPath('data.available_gateways.0.provider', 'midtrans')
            ->assertJsonPath('data.available_gateways.0.merchant_code', 'app-midtrans-sb')
            ->assertJsonPath('data.available_gateways.0.environment', 'sandbox')
            ->assertJsonPath('data.available_gateways.1.provider', 'dana')
            ->assertJsonPath('data.available_gateways.1.merchant_code', 'app-dana-prod')
            ->assertJsonPath('data.available_gateways.1.environment', 'production');
    }

    public function test_preview_returns_dana_sandbox_when_midtrans_is_production(): void
    {
        $this->setupGateways();

        MidtransEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'midtrans'],
            ['active_environment' => 'production']
        );
        DanaEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'dana'],
            [
                'active_environment' => 'sandbox',
                'primary_merchant_code' => 'test-dana-sb',
                'is_production_ready' => false,
            ]
        );

        $response = $this->withToken($this->token)
            ->postJson("/api/v1/integrations/customers/{$this->customer->external_customer_id}/invoice-preview", [
                'subscription_plan_code' => $this->plan->code,
                'period_count' => 1,
            ]);

        $response->assertOk()
            ->assertJsonCount(2, 'data.available_gateways')
            ->assertJsonPath('data.available_gateways.0.provider', 'midtrans')
            ->assertJsonPath('data.available_gateways.0.merchant_code', 'app-midtrans-prod')
            ->assertJsonPath('data.available_gateways.0.environment', 'production')
            ->assertJsonPath('data.available_gateways.1.provider', 'dana')
            ->assertJsonPath('data.available_gateways.1.merchant_code', 'app-dana-sb')
            ->assertJsonPath('data.available_gateways.1.environment', 'sandbox');
    }

    public function test_preview_returns_both_production_when_both_active_in_production(): void
    {
        $this->setupGateways();

        MidtransEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'midtrans'],
            ['active_environment' => 'production']
        );
        DanaEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'dana'],
            [
                'active_environment' => 'production',
                'primary_merchant_code' => 'test-dana-prod',
                'is_production_ready' => true,
            ]
        );

        $response = $this->withToken($this->token)
            ->postJson("/api/v1/integrations/customers/{$this->customer->external_customer_id}/invoice-preview", [
                'subscription_plan_code' => $this->plan->code,
                'period_count' => 1,
            ]);

        $response->assertOk()
            ->assertJsonCount(2, 'data.available_gateways')
            ->assertJsonPath('data.available_gateways.0.provider', 'midtrans')
            ->assertJsonPath('data.available_gateways.0.merchant_code', 'app-midtrans-prod')
            ->assertJsonPath('data.available_gateways.0.environment', 'production')
            ->assertJsonPath('data.available_gateways.1.provider', 'dana')
            ->assertJsonPath('data.available_gateways.1.merchant_code', 'app-dana-prod')
            ->assertJsonPath('data.available_gateways.1.environment', 'production');
    }

    public function test_preview_returns_both_sandbox_when_both_active_in_sandbox(): void
    {
        $this->setupGateways();

        MidtransEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'midtrans'],
            ['active_environment' => 'sandbox']
        );
        DanaEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'dana'],
            [
                'active_environment' => 'sandbox',
                'primary_merchant_code' => 'test-dana-sb',
                'is_production_ready' => false,
            ]
        );

        $response = $this->withToken($this->token)
            ->postJson("/api/v1/integrations/customers/{$this->customer->external_customer_id}/invoice-preview", [
                'subscription_plan_code' => $this->plan->code,
                'period_count' => 1,
            ]);

        $response->assertOk()
            ->assertJsonCount(2, 'data.available_gateways')
            ->assertJsonPath('data.available_gateways.0.provider', 'midtrans')
            ->assertJsonPath('data.available_gateways.0.merchant_code', 'app-midtrans-sb')
            ->assertJsonPath('data.available_gateways.0.environment', 'sandbox')
            ->assertJsonPath('data.available_gateways.1.provider', 'dana')
            ->assertJsonPath('data.available_gateways.1.merchant_code', 'app-dana-sb')
            ->assertJsonPath('data.available_gateways.1.environment', 'sandbox');
    }

    public function test_preview_isolates_gateways_to_current_application(): void
    {
        $this->setupGateways();

        // Create a separate tenant with its own gateways
        [$otherApp, $otherToken] = $this->createApplicationWithToken('OtherApp');
        ApplicationPaymentGateway::query()->create([
            'application_id' => $otherApp->id,
            'gateway_merchant_code' => 'otherapp-midtrans-sb',
            'provider' => 'midtrans',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);
        ApplicationPaymentGateway::query()->create([
            'application_id' => $otherApp->id,
            'gateway_merchant_code' => 'otherapp-dana-sb',
            'provider' => 'dana',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);

        MidtransEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'midtrans'],
            ['active_environment' => 'sandbox']
        );
        DanaEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'dana'],
            ['active_environment' => 'sandbox']
        );

        $response = $this->withToken($this->token)
            ->postJson("/api/v1/integrations/customers/{$this->customer->external_customer_id}/invoice-preview", [
                'subscription_plan_code' => $this->plan->code,
                'period_count' => 1,
            ]);

        $response->assertOk()
            ->assertJsonCount(2, 'data.available_gateways');

        $merchantCodes = collect($response->json('data.available_gateways'))->pluck('merchant_code')->all();
        $this->assertContains('app-midtrans-sb', $merchantCodes);
        $this->assertContains('app-dana-sb', $merchantCodes);
        $this->assertNotContains('otherapp-midtrans-sb', $merchantCodes);
        $this->assertNotContains('otherapp-dana-sb', $merchantCodes);
    }

    public function test_preview_excludes_inactive_gateways(): void
    {
        ApplicationPaymentGateway::query()->create([
            'application_id' => $this->testApplication->id,
            'gateway_merchant_code' => 'app-midtrans-sb',
            'provider' => 'midtrans',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);
        ApplicationPaymentGateway::query()->create([
            'application_id' => $this->testApplication->id,
            'gateway_merchant_code' => 'app-dana-sb-inactive',
            'provider' => 'dana',
            'environment' => 'sandbox',
            'is_active' => false,
        ]);

        MidtransEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'midtrans'],
            ['active_environment' => 'sandbox']
        );
        DanaEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'dana'],
            ['active_environment' => 'sandbox']
        );

        $response = $this->withToken($this->token)
            ->postJson("/api/v1/integrations/customers/{$this->customer->external_customer_id}/invoice-preview", [
                'subscription_plan_code' => $this->plan->code,
                'period_count' => 1,
            ]);

        $response->assertOk()
            ->assertJsonCount(1, 'data.available_gateways')
            ->assertJsonPath('data.available_gateways.0.merchant_code', 'app-midtrans-sb');
    }

    public function test_preview_supports_other_providers_in_sandbox_by_default(): void
    {
        ApplicationPaymentGateway::query()->create([
            'application_id' => $this->testApplication->id,
            'gateway_merchant_code' => 'app-doku-sb',
            'provider' => 'doku',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);

        MidtransEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'midtrans'],
            ['active_environment' => 'sandbox']
        );
        DanaEnvironmentSetting::query()->updateOrCreate(
            ['gateway' => 'dana'],
            ['active_environment' => 'sandbox']
        );

        $response = $this->withToken($this->token)
            ->postJson("/api/v1/integrations/customers/{$this->customer->external_customer_id}/invoice-preview", [
                'subscription_plan_code' => $this->plan->code,
                'period_count' => 1,
            ]);

        $response->assertOk()
            ->assertJsonCount(1, 'data.available_gateways')
            ->assertJsonPath('data.available_gateways.0.provider', 'doku')
            ->assertJsonPath('data.available_gateways.0.merchant_code', 'app-doku-sb')
            ->assertJsonPath('data.available_gateways.0.environment', 'sandbox');
    }

    private function setupGateways(): void
    {
        // Midtrans Sandbox & Production
        ApplicationPaymentGateway::query()->create([
            'application_id' => $this->testApplication->id,
            'gateway_merchant_code' => 'app-midtrans-sb',
            'provider' => 'midtrans',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);
        ApplicationPaymentGateway::query()->create([
            'application_id' => $this->testApplication->id,
            'gateway_merchant_code' => 'app-midtrans-prod',
            'provider' => 'midtrans',
            'environment' => 'production',
            'is_active' => true,
        ]);

        // DANA Sandbox & Production
        ApplicationPaymentGateway::query()->create([
            'application_id' => $this->testApplication->id,
            'gateway_merchant_code' => 'app-dana-sb',
            'provider' => 'dana',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);
        ApplicationPaymentGateway::query()->create([
            'application_id' => $this->testApplication->id,
            'gateway_merchant_code' => 'app-dana-prod',
            'provider' => 'dana',
            'environment' => 'production',
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0: Application, 1: string}
     */
    private function createApplicationWithToken(string $appName): array
    {
        $application = Application::factory()->create(['name' => $appName]);
        $plainToken = 'tok-'.strtolower($appName).'-'.bin2hex(random_bytes(8));

        $application->integrationTokens()->create([
            'name' => "Global Token {$appName}",
            'token_hash' => hash('sha256', $plainToken),
            'token_prefix' => substr($plainToken, 0, 8),
            'token_kind' => 'global',
            'status' => 'active',
            'scopes' => ['*'],
        ]);

        return [$application, $plainToken];
    }
}
