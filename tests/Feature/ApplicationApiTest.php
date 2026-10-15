<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicationApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_returns_401_when_no_sanctum_token_is_provided(): void
    {
        $this->getJson('/api/v1/admin/applications')
            ->assertUnauthorized();
    }

    public function test_returns_403_when_authenticated_user_is_not_an_admin(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/applications')
            ->assertForbidden();
    }

    public function test_valid_payload_creates_application_and_returns_201(): void
    {
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $payload = [
            'code' => 'UDEV-SALON',
            'name' => 'UDEV Salon',
            'description' => 'Aplikasi manajemen salon.',
            'application_url' => 'https://salon.udev.test',
            'contact_email' => 'admin@salon.udev.test',
            'is_active' => true,
        ];

        $this->postJson('/api/v1/admin/applications', $payload)
            ->assertCreated()
            ->assertJsonPath('data.code', 'UDEV-SALON')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('applications', $payload);
    }

    public function test_returns_422_when_application_code_is_not_unique(): void
    {
        Application::factory()->create(['code' => 'UDEV-CRM']);
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->postJson('/api/v1/admin/applications', [
            'code' => 'UDEV-CRM',
            'name' => 'CRM baru',
            'is_active' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code'])
            ->assertJsonPath('errors.code.0', 'Kode aplikasi sudah digunakan.');
    }

    public function test_returns_422_when_required_application_fields_are_missing(): void
    {
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->postJson('/api/v1/admin/applications', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'name', 'is_active'])
            ->assertJsonPath('message', 'Data yang dikirim belum valid. Periksa kembali isian Anda.')
            ->assertJsonPath('errors.code.0', 'Kode aplikasi wajib diisi.');
    }

    public function test_returns_specific_validation_message_for_an_invalid_application_contact_email(): void
    {
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->postJson('/api/v1/admin/applications', [
            'code' => 'UDEV-CRM',
            'name' => 'UDEV CRM',
            'contact_email' => 'bukan-email',
            'is_active' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.contact_email.0', 'Format Email kontak tidak valid.');
    }

    public function test_returns_a_safe_message_when_an_application_is_not_found(): void
    {
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->getJson('/api/v1/admin/applications/999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Data yang Anda cari tidak ditemukan.');
    }

    public function test_admin_can_show_and_update_an_application(): void
    {
        $application = Application::factory()->create(['name' => 'Nama lama']);
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->getJson("/api/v1/admin/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $application->id);

        $this->patchJson("/api/v1/admin/applications/{$application->id}", [
            'name' => 'Nama terbaru',
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nama terbaru')
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'name' => 'Nama terbaru',
            'is_active' => false,
        ]);
    }

    public function test_returns_paginated_and_filtered_applications_with_safe_sorting(): void
    {
        Application::factory()->create(['code' => 'UDEV-Z', 'is_active' => true]);
        Application::factory()->create(['code' => 'UDEV-A', 'is_active' => true]);
        Application::factory()->create(['code' => 'UDEV-OFF', 'is_active' => false]);
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->getJson('/api/v1/admin/applications?per_page=1&is_active=1&sort=code&direction=asc')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'UDEV-A')
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/admin/applications?sort=invalid_column')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort']);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }
}
