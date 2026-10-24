<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationIntegrationToken;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionPlanIntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_token_without_scopes_can_list_only_its_active_plans(): void
    {
        [$application, , $plaintext] = $this->integrationToken([
            'scopes' => [],
        ]);
        $visiblePlan = SubscriptionPlan::factory()->create([
            'application_id' => $application->id,
            'code' => 'BULANAN',
            'name' => 'Paket Bulanan',
            'description' => 'Akses selama 30 hari.',
            'duration_months' => 1,
            'duration_days' => 30,
            'price' => '175000.00',
            'currency' => 'IDR',
            'is_active' => true,
        ]);
        SubscriptionPlan::factory()->create([
            'application_id' => $application->id,
            'code' => 'NONAKTIF',
            'is_active' => false,
        ]);
        SubscriptionPlan::factory()->create([
            'application_id' => Application::factory(),
            'code' => 'APLIKASI-LAIN',
            'is_active' => true,
        ]);

        $this->withToken($plaintext)
            ->getJson('/api/v1/integrations/subscription-plans')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $visiblePlan->id)
            ->assertJsonPath('data.0.code', 'BULANAN')
            ->assertJsonPath('data.0.price', '175000.00')
            ->assertJsonPath('data.0.duration_days', 30)
            ->assertJsonPath('data.0.is_active', true)
            ->assertJsonMissing(['application_id']);

        $this->assertDatabaseHas('application_integration_audits', [
            'application_id' => $application->id,
            'operation' => 'subscription_plans.read',
            'status' => 'succeeded',
        ]);
    }

    public function test_empty_plan_list_returns_ok_with_an_empty_data_array(): void
    {
        [, , $plaintext] = $this->integrationToken([
            'scopes' => [],
        ]);

        $this->withToken($plaintext)
            ->getJson('/api/v1/integrations/subscription-plans')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_missing_invalid_expired_and_revoked_tokens_are_unauthorized(): void
    {
        [, , $validToken] = $this->integrationToken([
            'scopes' => [],
        ]);
        [, , $expiredToken] = $this->integrationToken([
            'scopes' => [],
            'expires_at' => now()->subMinute(),
        ]);
        [, $revokedModel, $revokedToken] = $this->integrationToken([
            'scopes' => [],
        ]);
        $revokedModel->update(['revoked_at' => now()]);

        $this->getJson('/api/v1/integrations/subscription-plans')->assertUnauthorized();
        $this->withToken('invalid-token')->getJson('/api/v1/integrations/subscription-plans')->assertUnauthorized();
        $this->withToken($expiredToken)->getJson('/api/v1/integrations/subscription-plans')->assertUnauthorized();
        $this->withToken($revokedToken)->getJson('/api/v1/integrations/subscription-plans')->assertUnauthorized();
        $this->withToken($validToken)->getJson('/api/v1/integrations/subscription-plans')->assertOk();
    }

    public function test_legacy_scopes_do_not_limit_a_valid_application_token(): void
    {
        [, , $token] = $this->integrationToken([
            'scopes' => [ApplicationIntegrationToken::ScopeCustomersRead],
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/integrations/subscription-plans')
            ->assertOk();
    }

    public function test_endpoint_does_not_change_billing_or_subscription_plan_data(): void
    {
        [$application, , $token] = $this->integrationToken([
            'scopes' => [],
        ]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id]);
        $originalPlan = $plan->only(['code', 'name', 'description', 'duration_days', 'price', 'currency', 'is_active']);
        $invoiceCount = Invoice::query()->count();
        $paymentAttemptCount = PaymentAttempt::query()->count();
        $subscriptionCount = Subscription::query()->count();

        $this->withToken($token)
            ->getJson('/api/v1/integrations/subscription-plans')
            ->assertOk();

        $this->assertSame($originalPlan, $plan->fresh()->only(array_keys($originalPlan)));
        $this->assertSame($invoiceCount, Invoice::query()->count());
        $this->assertSame($paymentAttemptCount, PaymentAttempt::query()->count());
        $this->assertSame($subscriptionCount, Subscription::query()->count());
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: Application, 1: ApplicationIntegrationToken, 2: string}
     */
    private function integrationToken(array $attributes = []): array
    {
        $application = Application::factory()->create();
        $plaintext = 'udev_test_'.fake()->uuid();
        $token = ApplicationIntegrationToken::factory()->create([
            'application_id' => $application->id,
            'token_hash' => hash('sha256', $plaintext),
            ...$attributes,
        ]);

        return [$application, $token, $plaintext];
    }
}
