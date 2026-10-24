<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\ApplicationIntegrationToken;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IntegrationCustomerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_token_can_create_upsert_and_update_a_customer_without_duplication(): void
    {
        [$application, $token, $plaintext] = $this->integrationToken();
        $payload = [
            'external_customer_id' => '125',
            'name' => 'Warung Maju Jaya',
            'owner_name' => 'Budi',
            'phone' => '081234567890',
            'email' => 'budi@example.com',
            'status' => 'active',
        ];

        $this->withToken($plaintext)
            ->postJson('/api/v1/integrations/customers', $payload)
            ->assertCreated()
            ->assertJsonPath('data.external_customer_id', '125')
            ->assertJsonPath('data.source', 'integration')
            ->assertJsonPath('data.sync_status', 'synced');

        $this->withToken($plaintext)
            ->postJson('/api/v1/integrations/customers', [...$payload, 'name' => 'Warung Maju Jaya Baru'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Warung Maju Jaya Baru');

        $this->withToken($plaintext)
            ->patchJson('/api/v1/integrations/customers/125', [
                'status' => 'inactive',
                'email' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.email', null);

        $this->withToken($plaintext)
            ->getJson('/api/v1/integrations/customers/125')
            ->assertOk()
            ->assertJsonPath('data.name', 'Warung Maju Jaya Baru')
            ->assertJsonPath('data.is_active', false);

        $this->assertSame($application->id, $token->application_id);
        $this->assertDatabaseCount('application_customers', 1);
        $this->assertDatabaseHas('application_integration_audits', [
            'application_id' => $application->id,
            'operation' => 'customer.created',
            'status' => 'succeeded',
        ]);
        $this->assertDatabaseHas('application_integration_audits', [
            'operation' => 'customer.updated',
            'status' => 'succeeded',
        ]);
    }

    public function test_same_external_id_is_isolated_between_applications(): void
    {
        [$firstApplication, , $firstToken] = $this->integrationToken();
        [$secondApplication, , $secondToken] = $this->integrationToken();
        $payload = [
            'external_customer_id' => 'same-id',
            'name' => 'Pelanggan',
            'status' => 'active',
        ];

        $this->withToken($firstToken)->postJson('/api/v1/integrations/customers', $payload)->assertCreated();
        $this->withToken($secondToken)->postJson('/api/v1/integrations/customers', $payload)->assertCreated();

        $this->assertDatabaseCount('application_customers', 2);
        $this->withToken($firstToken)
            ->getJson('/api/v1/integrations/customers/same-id')
            ->assertOk()
            ->assertJsonPath('data.application_id', $firstApplication->id);
        $this->withToken($secondToken)
            ->getJson('/api/v1/integrations/customers/same-id')
            ->assertOk()
            ->assertJsonPath('data.application_id', $secondApplication->id);
    }

    public function test_manual_customer_is_not_taken_over_by_integration(): void
    {
        [$application, $token, $plaintext] = $this->integrationToken();
        ApplicationCustomer::factory()->create([
            'application_id' => $application->id,
            'external_customer_id' => 'manual-1',
        ]);

        $this->withToken($plaintext)
            ->postJson('/api/v1/integrations/customers', [
                'external_customer_id' => 'manual-1',
                'name' => 'Tidak boleh diambil alih',
                'status' => 'active',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'manual_customer_conflict');
    }

    public function test_invalid_expired_and_revoked_tokens_are_rejected(): void
    {
        [$application, , $validToken] = $this->integrationToken();
        [, , $expiredToken] = $this->integrationToken(['expires_at' => now()->subMinute()]);
        [, $revokedModel, $revokedToken] = $this->integrationToken();
        $revokedModel->update(['revoked_at' => now()]);

        $this->withToken('invalid-token')->getJson('/api/v1/integrations/customers/125')->assertUnauthorized();
        $this->withToken($expiredToken)->getJson('/api/v1/integrations/customers/125')->assertUnauthorized();
        $this->withToken($revokedToken)->getJson('/api/v1/integrations/customers/125')->assertUnauthorized();
        $this->withToken($validToken)->getJson('/api/v1/admin/application-customers')->assertUnauthorized();
        $this->assertDatabaseHas('application_integration_audits', ['error_code' => 'invalid_token']);
        $this->assertDatabaseHas('application_integration_audits', ['error_code' => 'token_expired']);
        $this->assertDatabaseHas('application_integration_audits', ['error_code' => 'token_revoked']);
    }

    public function test_a_single_valid_application_token_can_access_customers_and_subscription_plans_without_scopes(): void
    {
        [$application, , $plaintext] = $this->integrationToken(['scopes' => []]);
        SubscriptionPlan::factory()->create([
            'application_id' => $application->id,
            'duration_days' => 30,
        ]);

        $this->withToken($plaintext)
            ->postJson('/api/v1/integrations/customers', [
                'external_customer_id' => 'single-token-customer',
                'name' => 'Pelanggan Token Tunggal',
                'status' => 'active',
            ])
            ->assertCreated();

        $this->withToken($plaintext)
            ->getJson('/api/v1/integrations/subscription-plans')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_payload_cannot_change_billing_fields_and_rate_limit_is_bounded(): void
    {
        [$application, $token, $plaintext] = $this->integrationToken();

        $this->withToken($plaintext)
            ->postJson('/api/v1/integrations/customers', [
                'external_customer_id' => 'invalid-fields',
                'name' => 'Invalid',
                'status' => 'active',
                'subscription_id' => 99,
            ])
            ->assertUnprocessable();

        RateLimiter::clear('integration-api:token:'.$application->id.':'.$token->id);
        RateLimiter::clear('integration-api:ip:127.0.0.1');
        for ($index = 0; $index < 60; $index++) {
            $this->withToken($plaintext)->getJson('/api/v1/integrations/customers/not-found');
        }
        $this->withToken($plaintext)
            ->getJson('/api/v1/integrations/customers/not-found')
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_admin_sanctum_token_is_not_an_integration_token(): void
    {
        $adminToken = User::factory()
            ->create(['is_admin' => true])
            ->createToken('admin', ['admin:access'])
            ->plainTextToken;

        $this->withToken($adminToken)
            ->getJson('/api/v1/integrations/customers/anything')
            ->assertUnauthorized();
    }

    public function test_admin_customer_list_can_filter_integration_source(): void
    {
        [$application, , $plaintext] = $this->integrationToken();
        ApplicationCustomer::factory()->create([
            'application_id' => $application->id,
            'external_customer_id' => 'integration-1',
            'source' => 'integration',
        ]);
        ApplicationCustomer::factory()->create([
            'application_id' => $application->id,
            'external_customer_id' => 'manual-1',
            'source' => 'manual',
        ]);
        $this->withToken($plaintext)->getJson('/api/v1/integrations/customers/integration-1');

        Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['admin:access']);
        $this->getJson('/api/v1/admin/application-customers?source=integration')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.source', 'integration');
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
