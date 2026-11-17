<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceEnvironmentTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_invoice_list_and_detail_return_dana_sandbox_environment_for_sandbox_payment_attempt(): void
    {
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id]);
        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
        ]);

        PaymentAttempt::factory()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'provider' => 'dana',
            'gateway_environment' => 'sandbox',
            'status' => PaymentAttempt::StatusPending,
        ]);

        Sanctum::actingAs($this->admin(), ['admin:access']);

        // Test Invoice List
        $listResponse = $this->getJson('/api/v1/admin/invoices')
            ->assertOk()
            ->assertJsonPath('data.0.id', $invoice->id)
            ->assertJsonPath('data.0.gateway_environment', 'sandbox')
            ->assertJsonPath('data.0.environment', 'sandbox')
            ->assertJsonPath('data.0.payment_attempts.0.provider', 'dana')
            ->assertJsonPath('data.0.payment_attempts.0.gateway_environment', 'sandbox')
            ->assertJsonPath('data.0.payment_attempts.0.environment', 'sandbox');

        // Test Invoice Detail
        $detailResponse = $this->getJson("/api/v1/admin/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $invoice->id)
            ->assertJsonPath('data.gateway_environment', 'sandbox')
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.payment_attempts.0.provider', 'dana')
            ->assertJsonPath('data.payment_attempts.0.gateway_environment', 'sandbox')
            ->assertJsonPath('data.payment_attempts.0.environment', 'sandbox');
    }

    public function test_invoice_list_and_detail_return_dana_production_environment_for_production_payment_attempt(): void
    {
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id]);
        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
        ]);

        PaymentAttempt::factory()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'provider' => 'dana',
            'gateway_environment' => 'production',
            'status' => PaymentAttempt::StatusPending,
        ]);

        Sanctum::actingAs($this->admin(), ['admin:access']);

        // Test Invoice List
        $this->getJson('/api/v1/admin/invoices')
            ->assertOk()
            ->assertJsonPath('data.0.id', $invoice->id)
            ->assertJsonPath('data.0.gateway_environment', 'production')
            ->assertJsonPath('data.0.environment', 'production')
            ->assertJsonPath('data.0.payment_attempts.0.provider', 'dana')
            ->assertJsonPath('data.0.payment_attempts.0.gateway_environment', 'production')
            ->assertJsonPath('data.0.payment_attempts.0.environment', 'production');

        // Test Invoice Detail
        $this->getJson("/api/v1/admin/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $invoice->id)
            ->assertJsonPath('data.gateway_environment', 'production')
            ->assertJsonPath('data.environment', 'production')
            ->assertJsonPath('data.payment_attempts.0.provider', 'dana')
            ->assertJsonPath('data.payment_attempts.0.gateway_environment', 'production')
            ->assertJsonPath('data.payment_attempts.0.environment', 'production');
    }

    public function test_environment_uses_latest_payment_attempt_when_multiple_attempts_exist(): void
    {
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id]);
        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
        ]);

        // Attempt 1: Sandbox (failed)
        PaymentAttempt::factory()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'provider' => 'dana',
            'gateway_environment' => 'sandbox',
            'status' => PaymentAttempt::StatusFailed,
        ]);

        // Attempt 2: Production (active)
        PaymentAttempt::factory()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 2,
            'provider' => 'dana',
            'gateway_environment' => 'production',
            'status' => PaymentAttempt::StatusPending,
        ]);

        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->getJson('/api/v1/admin/invoices')
            ->assertOk()
            ->assertJsonPath('data.0.gateway_environment', 'production')
            ->assertJsonPath('data.0.payment_attempts.0.gateway_environment', 'production')
            ->assertJsonPath('data.0.payment_attempts.1.gateway_environment', 'sandbox');
    }

    public function test_environment_falls_back_to_metadata_when_gateway_environment_column_is_empty(): void
    {
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id]);
        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
        ]);

        PaymentAttempt::factory()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'provider' => 'dana',
            'gateway_environment' => null,
            'metadata' => [
                'gateway_environment' => 'production',
            ],
            'status' => PaymentAttempt::StatusPending,
        ]);

        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->getJson('/api/v1/admin/invoices')
            ->assertOk()
            ->assertJsonPath('data.0.gateway_environment', 'production')
            ->assertJsonPath('data.0.payment_attempts.0.gateway_environment', 'production')
            ->assertJsonPath('data.0.payment_attempts.0.environment', 'production');
    }

    public function test_payment_attempts_api_returns_environment_consistently(): void
    {
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id]);
        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
        ]);

        $sandboxAttempt = PaymentAttempt::factory()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'provider' => 'dana',
            'gateway_environment' => 'sandbox',
        ]);

        $productionAttempt = PaymentAttempt::factory()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 2,
            'provider' => 'dana',
            'gateway_environment' => 'production',
        ]);

        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->getJson("/api/v1/admin/payment-attempts/{$sandboxAttempt->id}")
            ->assertOk()
            ->assertJsonPath('data.gateway_environment', 'sandbox')
            ->assertJsonPath('data.environment', 'sandbox');

        $this->getJson("/api/v1/admin/payment-attempts/{$productionAttempt->id}")
            ->assertOk()
            ->assertJsonPath('data.gateway_environment', 'production')
            ->assertJsonPath('data.environment', 'production');
    }
}
