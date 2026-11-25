<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\ApplicationIntegrationAudit;
use App\Models\ApplicationIntegrationToken;
use App\Models\ApplicationPaymentGateway;
use App\Models\CustomerReferral;
use App\Models\DanaPaymentFeeSetting;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
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

    public function test_delete_application_requires_admin_authentication_and_authorization(): void
    {
        $application = Application::factory()->create();

        $this->deleteJson("/api/v1/admin/applications/{$application->id}")
            ->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(), ['admin:access']);
        $this->deleteJson("/api/v1/admin/applications/{$application->id}")
            ->assertForbidden();

        Sanctum::actingAs($this->admin(), []);
        $this->deleteJson("/api/v1/admin/applications/{$application->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('applications', ['id' => $application->id]);
    }

    public function test_admin_can_permanently_delete_application_without_related_data(): void
    {
        $application = Application::factory()->create();
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->deleteJson("/api/v1/admin/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Aplikasi berhasil dihapus.');

        $this->assertDatabaseMissing('applications', ['id' => $application->id]);
    }

    public function test_delete_non_existent_application_returns_404(): void
    {
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->deleteJson('/api/v1/admin/applications/999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Data yang Anda cari tidak ditemukan.');
    }

    public function test_delete_application_with_customers_or_billing_data_returns_409_without_cascade_delete(): void
    {
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id]);
        $subscription = Subscription::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
        ]);
        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'subscription_id' => $subscription->id,
        ]);
        $paymentAttempt = PaymentAttempt::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => $invoice->amount,
            'currency' => $invoice->currency,
        ]);

        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->deleteJson("/api/v1/admin/applications/{$application->id}")
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Aplikasi tidak dapat dihapus karena memiliki data terkait. Silakan nonaktifkan aplikasi.',
            );

        $this->assertDatabaseHas('applications', ['id' => $application->id]);
        $this->assertDatabaseHas('application_customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('subscription_plans', ['id' => $plan->id]);
        $this->assertDatabaseHas('subscriptions', ['id' => $subscription->id]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseHas('payment_attempts', ['id' => $paymentAttempt->id]);
    }

    public function test_delete_application_with_referral_returns_409(): void
    {
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $referral = CustomerReferral::factory()->create([
            'application_id' => $application->id,
            'application_customer_id' => $customer->id,
        ]);

        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->deleteJson("/api/v1/admin/applications/{$application->id}")
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Aplikasi tidak dapat dihapus karena memiliki data terkait. Silakan nonaktifkan aplikasi.',
            );

        $this->assertDatabaseHas('applications', ['id' => $application->id]);
        $this->assertDatabaseHas('customer_referrals', ['id' => $referral->id]);
    }

    public function test_delete_application_with_payment_gateway_or_dana_fee_setting_returns_409_without_cascade_delete(): void
    {
        $gatewayApp = Application::factory()->create();
        $gateway = ApplicationPaymentGateway::query()->create([
            'application_id' => $gatewayApp->id,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'udev-central',
            'is_active' => true,
        ]);

        $feeApp = Application::factory()->create();
        $feeSetting = DanaPaymentFeeSetting::query()->create([
            'application_id' => $feeApp->id,
            'payment_method' => 'qris',
            'environment' => 'sandbox',
            'fee_type' => DanaPaymentFeeSetting::FeeTypeDana,
            'fee_value' => 0.7,
            'fee_unit' => DanaPaymentFeeSetting::FeeUnitPercent,
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->deleteJson("/api/v1/admin/applications/{$gatewayApp->id}")
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Aplikasi tidak dapat dihapus karena memiliki data terkait. Silakan nonaktifkan aplikasi.',
            );

        $this->deleteJson("/api/v1/admin/applications/{$feeApp->id}")
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Aplikasi tidak dapat dihapus karena memiliki data terkait. Silakan nonaktifkan aplikasi.',
            );

        $this->assertDatabaseHas('applications', ['id' => $gatewayApp->id]);
        $this->assertDatabaseHas('application_payment_gateways', ['id' => $gateway->id]);
        $this->assertDatabaseHas('applications', ['id' => $feeApp->id]);
        $this->assertDatabaseHas('dana_payment_fee_settings', ['id' => $feeSetting->id]);
    }

    public function test_delete_application_with_integration_token_or_audit_returns_409(): void
    {
        $tokenApp = Application::factory()->create();
        $token = ApplicationIntegrationToken::factory()->create([
            'application_id' => $tokenApp->id,
        ]);

        $auditApp = Application::factory()->create();
        $audit = ApplicationIntegrationAudit::query()->create([
            'application_id' => $auditApp->id,
            'operation' => 'customer.sync',
            'status' => 'succeeded',
            'request_id' => 'req-audit-001',
        ]);

        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->deleteJson("/api/v1/admin/applications/{$tokenApp->id}")
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Aplikasi tidak dapat dihapus karena memiliki data terkait. Silakan nonaktifkan aplikasi.',
            );

        $this->deleteJson("/api/v1/admin/applications/{$auditApp->id}")
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Aplikasi tidak dapat dihapus karena memiliki data terkait. Silakan nonaktifkan aplikasi.',
            );

        $this->assertDatabaseHas('applications', ['id' => $tokenApp->id]);
        $this->assertDatabaseHas('application_integration_tokens', ['id' => $token->id]);
        $this->assertDatabaseHas('applications', ['id' => $auditApp->id]);
        $this->assertDatabaseHas('application_integration_audits', ['id' => $audit->id]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }
}
