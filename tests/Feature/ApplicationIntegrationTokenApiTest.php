<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationIntegrationToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicationIntegrationTokenApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_list_and_revoke_an_integration_token(): void
    {
        $application = Application::factory()->create();
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['admin:access']);

        $response = $this->postJson("/api/v1/admin/applications/{$application->id}/integration-tokens", [
            'name' => 'Larissama production sync',
            'scopes' => [ApplicationIntegrationToken::ScopeCustomersRead, ApplicationIntegrationToken::ScopeCustomersWrite],
            'expires_at' => now()->addMonth()->toISOString(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Larissama production sync')
            ->assertJsonPath('data.scopes.0', ApplicationIntegrationToken::ScopeCustomersRead)
            ->assertJsonStructure(['data' => ['id', 'token_prefix'], 'token', 'token_type', 'warning'])
            ->assertJsonMissing(['token_hash']);

        $plaintext = $response->json('token');
        $token = ApplicationIntegrationToken::query()->sole();
        $this->assertNotSame('', $plaintext);
        $this->assertSame(hash('sha256', $plaintext), $token->token_hash);

        $this->getJson("/api/v1/admin/applications/{$application->id}/integration-tokens")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Larissama production sync')
            ->assertJsonMissing(['token_hash'])
            ->assertJsonMissing(['token']);

        $this->deleteJson("/api/v1/admin/applications/{$application->id}/integration-tokens/{$token->id}")
            ->assertOk()
            ->assertJsonPath('data.revoked_at', fn ($value): bool => $value !== null);

        $this->assertNotNull($token->refresh()->revoked_at);
    }

    public function test_non_admin_cannot_manage_integration_tokens(): void
    {
        $application = Application::factory()->create();
        Sanctum::actingAs(User::factory()->create(['is_admin' => false]), ['admin:access']);

        $this->getJson("/api/v1/admin/applications/{$application->id}/integration-tokens")
            ->assertForbidden();
    }

    public function test_admin_can_issue_a_token_with_subscription_plan_read_scope(): void
    {
        $application = Application::factory()->create();
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['admin:access']);

        $this->postJson("/api/v1/admin/applications/{$application->id}/integration-tokens", [
            'name' => 'Larissama paket langganan',
            'scopes' => [ApplicationIntegrationToken::ScopeSubscriptionPlansRead],
        ])
            ->assertCreated()
            ->assertJsonPath('data.scopes.0', ApplicationIntegrationToken::ScopeSubscriptionPlansRead);
    }

    public function test_admin_can_review_safe_integration_audit_metadata(): void
    {
        $application = Application::factory()->create();
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['admin:access']);
        $plaintext = 'udev_audit_'.fake()->uuid();
        $token = ApplicationIntegrationToken::factory()->create([
            'application_id' => $application->id,
            'token_hash' => hash('sha256', $plaintext),
        ]);

        $this->withToken($plaintext)
            ->getJson('/api/v1/integrations/customers/audit-1')
            ->assertNotFound();

        $this->getJson("/api/v1/admin/applications/{$application->id}/integration-audits")
            ->assertOk()
            ->assertJsonPath('data.0.operation', 'customer.read')
            ->assertJsonMissing(['token_hash', 'token']);

        $this->assertSame($token->id, $token->refresh()->id);
    }
}
