<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\ApplicationIntegrationToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicationIntegrationTestApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_duplicate_update_read_and_audit_test_customer(): void
    {
        $application = Application::factory()->create(['code' => 'LARISSAMA']);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['admin:access']);
        $baseUrl = "/api/v1/admin/applications/{$application->id}/integration-tests";
        $payload = [
            'external_customer_id' => 'UAT-LARISSAMA-001',
            'name' => 'Pelanggan Uji',
            'owner_name' => 'Pemilik Uji',
            'phone' => '081234567890',
            'email' => 'uat@example.com',
            'status' => 'active',
        ];

        $this->postJson("{$baseUrl}/customers", $payload)
            ->assertCreated()
            ->assertJsonPath('data.is_test', true)
            ->assertJsonPath('test.authentication_mode', 'admin_internal')
            ->assertJsonPath('test.created', true)
            ->assertJsonPath('data.external_customer_id', 'UAT-LARISSAMA-001')
            ->assertJsonPath('request_id', fn ($value): bool => is_string($value) && $value !== '')
            ->assertJsonMissing(['token', 'token_hash']);

        $this->postJson("{$baseUrl}/customers", [...$payload, 'name' => 'Pelanggan Uji Dikirim Ulang'])
            ->assertOk()
            ->assertJsonPath('test.created', false)
            ->assertJsonPath('data.name', 'Pelanggan Uji Dikirim Ulang');

        $this->patchJson("{$baseUrl}/customers/UAT-LARISSAMA-001", [
            'name' => 'Pelanggan Uji Diperbarui',
            'phone' => '089999999999',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Pelanggan Uji Diperbarui')
            ->assertJsonPath('data.phone_number', '089999999999');

        $this->getJson("{$baseUrl}/customers/UAT-LARISSAMA-001")
            ->assertOk()
            ->assertJsonPath('data.is_test', true)
            ->assertJsonPath('data.name', 'Pelanggan Uji Diperbarui');

        $this->getJson("{$baseUrl}/audits")
            ->assertOk()
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('data.0.safe_details.is_test', true);

        $this->assertDatabaseCount('application_customers', 1);
        $this->assertDatabaseHas('application_customers', [
            'application_id' => $application->id,
            'external_customer_id' => 'UAT-LARISSAMA-001',
            'is_test' => true,
        ]);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_test_endpoint_cannot_take_over_manual_or_production_integration_customer(): void
    {
        $application = Application::factory()->create();
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['admin:access']);
        $url = "/api/v1/admin/applications/{$application->id}/integration-tests/customers";
        ApplicationCustomer::factory()->create([
            'application_id' => $application->id,
            'external_customer_id' => 'UAT-MANUAL-001',
            'source' => 'manual',
            'is_test' => false,
        ]);

        $this->postJson($url, [
            'external_customer_id' => 'UAT-MANUAL-001',
            'name' => 'Tidak boleh',
            'status' => 'active',
        ])->assertStatus(409)->assertJsonPath('error_code', 'manual_customer_conflict');

        $plaintext = 'udev_production_'.fake()->uuid();
        $token = ApplicationIntegrationToken::factory()->create([
            'application_id' => $application->id,
            'token_hash' => hash('sha256', $plaintext),
        ]);
        $this->withToken($plaintext)->postJson('/api/v1/integrations/customers', [
            'external_customer_id' => 'UAT-PRODUCTION-001',
            'name' => 'Pelanggan Produksi',
            'status' => 'active',
        ])->assertCreated();

        $this->postJson($url, [
            'external_customer_id' => 'UAT-PRODUCTION-001',
            'name' => 'Tidak boleh diuji',
            'status' => 'active',
        ])->assertStatus(409)->assertJsonPath('error_code', 'test_customer_conflict');

        $this->assertSame($application->id, $token->application_id);
    }

    public function test_test_panel_requires_admin_and_is_rate_limited(): void
    {
        $application = Application::factory()->create();
        $url = "/api/v1/admin/applications/{$application->id}/integration-tests/customers/UAT-RATE-001";
        $this->getJson($url)->assertUnauthorized();

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);
        RateLimiter::clear("admin-integration-test:{$admin->id}:{$application->id}:127.0.0.1");

        for ($index = 0; $index < 20; $index++) {
            $this->getJson($url)->assertNotFound();
        }

        $this->getJson($url)->assertStatus(429)->assertHeader('Retry-After');
    }
}
