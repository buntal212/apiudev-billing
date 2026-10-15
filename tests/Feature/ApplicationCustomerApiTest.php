<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicationCustomerApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_returns_401_when_no_sanctum_token_is_provided(): void
    {
        $this->getJson('/api/v1/admin/application-customers')
            ->assertUnauthorized();
    }

    public function test_returns_403_when_authenticated_user_is_not_an_admin(): void
    {
        $customer = ApplicationCustomer::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/admin/application-customers/{$customer->id}")
            ->assertForbidden();
    }

    public function test_valid_payload_creates_application_customer_and_returns_201(): void
    {
        $application = Application::factory()->create();
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $payload = [
            'application_id' => $application->id,
            'external_customer_id' => 'SALON-0001',
            'name' => 'Siti UDEV',
            'email' => 'siti@example.test',
            'phone_number' => '08123456789',
            'is_active' => true,
        ];

        $this->postJson('/api/v1/admin/application-customers', $payload)
            ->assertCreated()
            ->assertJsonPath('data.application_id', $application->id)
            ->assertJsonPath('data.external_customer_id', 'SALON-0001');

        $this->assertDatabaseHas('application_customers', $payload);
    }

    public function test_external_customer_id_is_unique_within_its_application_only(): void
    {
        $firstApplication = Application::factory()->create();
        $secondApplication = Application::factory()->create();
        ApplicationCustomer::factory()->create([
            'application_id' => $firstApplication->id,
            'external_customer_id' => 'CUSTOMER-001',
        ]);
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->postJson('/api/v1/admin/application-customers', [
            'application_id' => $firstApplication->id,
            'external_customer_id' => 'CUSTOMER-001',
            'name' => 'Duplikat',
            'is_active' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['external_customer_id'])
            ->assertJsonPath('errors.external_customer_id.0', 'ID pelanggan pada aplikasi sudah digunakan.');

        $this->postJson('/api/v1/admin/application-customers', [
            'application_id' => $secondApplication->id,
            'external_customer_id' => 'CUSTOMER-001',
            'name' => 'Pelanggan aplikasi lain',
            'is_active' => true,
        ])
            ->assertCreated();
    }

    public function test_returns_422_when_required_customer_fields_are_missing(): void
    {
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->postJson('/api/v1/admin/application-customers', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['application_id', 'external_customer_id', 'name', 'is_active'])
            ->assertJsonPath('message', 'Data yang dikirim belum valid. Periksa kembali isian Anda.')
            ->assertJsonPath('errors.name.0', 'Nama wajib diisi.');
    }

    public function test_returns_specific_validation_message_for_an_invalid_customer_email(): void
    {
        $application = Application::factory()->create();
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->postJson('/api/v1/admin/application-customers', [
            'application_id' => $application->id,
            'external_customer_id' => 'CUSTOMER-INVALID-EMAIL',
            'name' => 'Pelanggan Uji',
            'email' => 'bukan-email',
            'is_active' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Format Email tidak valid.');
    }

    public function test_admin_can_show_and_update_an_application_customer(): void
    {
        $customer = ApplicationCustomer::factory()->create(['name' => 'Nama lama']);
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->getJson("/api/v1/admin/application-customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $customer->id);

        $this->patchJson("/api/v1/admin/application-customers/{$customer->id}", [
            'name' => 'Nama terbaru',
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nama terbaru')
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('application_customers', [
            'id' => $customer->id,
            'name' => 'Nama terbaru',
            'is_active' => false,
        ]);
    }

    public function test_returns_paginated_and_filtered_customers_with_safe_sorting(): void
    {
        $application = Application::factory()->create();
        $otherApplication = Application::factory()->create();
        ApplicationCustomer::factory()->create([
            'application_id' => $application->id,
            'external_customer_id' => 'CUSTOMER-Z',
            'is_active' => true,
        ]);
        ApplicationCustomer::factory()->create([
            'application_id' => $application->id,
            'external_customer_id' => 'CUSTOMER-A',
            'is_active' => true,
        ]);
        ApplicationCustomer::factory()->create([
            'application_id' => $otherApplication->id,
            'external_customer_id' => 'CUSTOMER-OTHER',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->getJson("/api/v1/admin/application-customers?application_id={$application->id}&per_page=1&sort=external_customer_id&direction=asc")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.external_customer_id', 'CUSTOMER-A')
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/admin/application-customers?sort=invalid_column')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort']);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }
}
