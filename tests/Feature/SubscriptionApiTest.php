<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\PaymentAttempt;
use App\Models\SettlementEvent;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_list_and_filter_subscriptions_by_application_and_customer(): void
    {
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id]);
        $subscription = Subscription::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
        ]);
        Subscription::factory()->create();
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->getJson("/api/v1/admin/subscriptions?application_id={$application->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $subscription->id)
            ->assertJsonPath('data.0.customer.id', $customer->id)
            ->assertJsonPath('data.0.plan.id', $plan->id);

        $this->getJson("/api/v1/admin/subscriptions?application_customer_id={$customer->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $subscription->id);
    }

    public function test_admin_can_view_subscription_detail(): void
    {
        $subscription = Subscription::factory()->create();
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->getJson("/api/v1/admin/subscriptions/{$subscription->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $subscription->id)
            ->assertJsonPath('data.status', Subscription::StatusActive);
    }

    public function test_subscription_detail_includes_related_invoices_and_payment_attempts(): void
    {
        $subscription = Subscription::factory()->create();
        $invoice = Invoice::factory()->create([
            'application_customer_id' => $subscription->application_customer_id,
            'subscription_plan_id' => $subscription->subscription_plan_id,
            'subscription_id' => $subscription->id,
        ]);
        $attempt = PaymentAttempt::factory()->create([
            'invoice_id' => $invoice->id,
            'merchant_reference' => $invoice->invoice_number.'-A1',
        ]);
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->getJson("/api/v1/admin/subscriptions/{$subscription->id}")
            ->assertOk()
            ->assertJsonPath('data.invoices.0.id', $invoice->id)
            ->assertJsonPath('data.invoices.0.payment_attempts.0.id', $attempt->id);
    }

    public function test_subscriptions_require_an_admin_token(): void
    {
        $this->getJson('/api/v1/admin/subscriptions')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Sesi Anda telah berakhir. Silakan masuk kembali.');
    }

    public function test_non_admin_cannot_view_subscriptions(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['admin:access']);

        $this->getJson('/api/v1/admin/subscriptions')
            ->assertForbidden();
    }

    public function test_admin_can_recover_a_paid_invoice_without_creating_a_new_payment(): void
    {
        $invoice = Invoice::factory()->create([
            'status' => Invoice::StatusPaid,
            'paid_at' => now()->subMinute(),
        ]);
        $attempt = PaymentAttempt::factory()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'provider' => 'dana',
            'gateway_environment' => 'sandbox',
            'gateway_payment_id' => 'gateway-'.fake()->uuid(),
            'merchant_reference' => $invoice->invoice_number.'-A1',
            'status' => PaymentAttempt::StatusSucceeded,
            'amount' => $invoice->amount,
            'currency' => $invoice->currency,
        ]);
        $settlementKey = 'settlement:payment-attempt:'.$attempt->id;
        PaymentAllocation::query()->create([
            'invoice_id' => $invoice->id,
            'payment_attempt_id' => $attempt->id,
            'amount' => $invoice->amount,
            'currency' => $invoice->currency,
            'allocated_at' => $invoice->paid_at,
            'idempotency_key' => $settlementKey,
        ]);
        SettlementEvent::query()->create([
            'invoice_id' => $invoice->id,
            'payment_attempt_id' => $attempt->id,
            'event_type' => SettlementEvent::EventSettlement,
            'status' => SettlementEvent::StatusCompleted,
            'idempotency_key' => $settlementKey,
            'processed_at' => $invoice->paid_at,
        ]);

        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/subscription/recover")
            ->assertOk()
            ->assertJsonPath('data.status', Invoice::StatusPaid)
            ->assertJsonPath('data.subscription.status', Subscription::StatusActive);

        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/subscription/recover")
            ->assertOk()
            ->assertJsonPath('data.subscription.id', $invoice->fresh()->subscription_id);

        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('subscription_period_events', 1);
    }

    public function test_recovery_requires_admin_authorization(): void
    {
        $invoice = Invoice::factory()->create(['status' => Invoice::StatusPaid]);

        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/subscription/recover")
            ->assertUnauthorized();
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }
}
