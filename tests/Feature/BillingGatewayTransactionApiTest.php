<?php

namespace Tests\Feature;

use App\Models\ApplicationCustomer;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\PaymentAttempt;
use App\Models\PaymentReconciliationRun;
use App\Models\SettlementEvent;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingGatewayTransactionApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_trace_a_verified_billing_payment_without_sensitive_checkout_data(): void
    {
        $customer = ApplicationCustomer::factory()->create(['name' => 'Warung Sukses']);
        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'status' => Invoice::StatusPaid,
            'paid_at' => now(),
        ]);
        $subscription = Subscription::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $invoice->subscription_plan_id,
        ]);
        $invoice->update(['subscription_id' => $subscription->id]);
        $attempt = $this->sandboxAttempt($invoice, [
            'status' => PaymentAttempt::StatusSucceeded,
            'completed_at' => now(),
            'metadata' => ['api_key' => 'must-not-be-exposed'],
        ]);
        $this->reconciliationRun($attempt, PaymentReconciliationRun::StatusPaid, 'paid');
        PaymentAllocation::query()->create([
            'invoice_id' => $invoice->id,
            'payment_attempt_id' => $attempt->id,
            'amount' => $attempt->amount,
            'currency' => $attempt->currency,
            'allocated_at' => now(),
            'idempotency_key' => 'allocation:'.$attempt->id,
        ]);
        SettlementEvent::query()->create([
            'invoice_id' => $invoice->id,
            'payment_attempt_id' => $attempt->id,
            'event_type' => SettlementEvent::EventSettlement,
            'status' => SettlementEvent::StatusCompleted,
            'idempotency_key' => 'settlement:'.$attempt->id,
            'processed_at' => now(),
        ]);

        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->getJson('/api/v1/admin/payment-gateway/billing-transactions?customer=Warung')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.application.id', $customer->application_id)
            ->assertJsonPath('data.0.customer.name', 'Warung Sukses')
            ->assertJsonPath('data.0.invoice.number', $invoice->invoice_number)
            ->assertJsonPath('data.0.payment_attempt.number', 1)
            ->assertJsonPath('data.0.gateway.environment', 'sandbox')
            ->assertJsonPath('data.0.gateway.status', 'paid')
            ->assertJsonPath('data.0.billing_sync.status', 'synchronized')
            ->assertJsonPath('data.0.confirmation.payment_proof_valid', true)
            ->assertJsonPath('data.0.settlement.subscription.id', $subscription->id)
            ->assertJsonMissing(['api_key' => 'must-not-be-exposed']);

        $this->getJson("/api/v1/admin/payment-gateway/billing-transactions/{$attempt->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $attempt->id)
            ->assertJsonPath('data.settlement.events.0.status', SettlementEvent::StatusCompleted);
    }

    public function test_filters_repeated_attempts_and_isolates_other_applications(): void
    {
        $firstCustomer = ApplicationCustomer::factory()->create(['name' => 'Warung Uji']);
        $invoice = Invoice::factory()->create([
            'application_customer_id' => $firstCustomer->id,
            'invoice_number' => 'INV-RETRY-001',
        ]);
        $first = $this->sandboxAttempt($invoice, [
            'attempt_number' => 1,
            'status' => PaymentAttempt::StatusExpired,
            'requested_at' => now()->subDay(),
        ]);
        $second = $this->sandboxAttempt($invoice, [
            'attempt_number' => 2,
            'status' => PaymentAttempt::StatusPending,
        ]);

        $otherCustomer = ApplicationCustomer::factory()->create(['name' => 'Warung Lain']);
        $otherInvoice = Invoice::factory()->create([
            'application_customer_id' => $otherCustomer->id,
            'invoice_number' => 'INV-RETRY-OTHER',
        ]);
        $this->sandboxAttempt($otherInvoice, ['status' => PaymentAttempt::StatusExpired]);

        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->getJson('/api/v1/admin/payment-gateway/billing-transactions?application_id='.$firstCustomer->application_id.'&invoice_number=RETRY&sort=attempt_number&direction=desc')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.application.id', $firstCustomer->application_id)
            ->assertJsonPath('data.0.payment_attempt.id', $second->id)
            ->assertJsonPath('data.1.payment_attempt.id', $first->id);

        $this->getJson('/api/v1/admin/payment-gateway/billing-transactions?invoice_number=RETRY&status=expired&date_from='.now()->subDays(2)->format('Y-m-d').'&date_to='.now()->format('Y-m-d'))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_marks_attempt_without_gateway_reference_as_an_anomaly(): void
    {
        $attempt = $this->sandboxAttempt(Invoice::factory()->create(), [
            'gateway_payment_id' => null,
            'merchant_reference' => null,
            'provider_reference' => null,
            'status' => PaymentAttempt::StatusPending,
        ]);
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->getJson("/api/v1/admin/payment-gateway/billing-transactions/{$attempt->id}")
            ->assertOk()
            ->assertJsonPath('data.gateway.record_status', 'gateway_not_found')
            ->assertJsonPath('data.billing_sync.status', 'gateway_not_found')
            ->assertJsonPath('data.anomaly.code', 'gateway_transaction_missing');
    }

    public function test_requires_an_administrator(): void
    {
        $this->getJson('/api/v1/admin/payment-gateway/billing-transactions')
            ->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(), ['admin:access']);

        $this->getJson('/api/v1/admin/payment-gateway/billing-transactions')
            ->assertForbidden();
    }

    /** @param array<string, mixed> $overrides */
    private function sandboxAttempt(Invoice $invoice, array $overrides = []): PaymentAttempt
    {
        return PaymentAttempt::factory()->create(array_merge([
            'invoice_id' => $invoice->id,
            'amount' => $invoice->amount,
            'currency' => $invoice->currency,
            'provider' => 'DANA',
            'merchant_reference' => 'BILLING-'.$invoice->invoice_number,
            'provider_reference' => 'DANA-'.Str::upper(Str::random(10)),
            'gateway_merchant_code' => 'udev-billing',
            'gateway_environment' => 'sandbox',
            'gateway_payment_id' => (string) Str::ulid(),
        ], $overrides));
    }

    private function reconciliationRun(
        PaymentAttempt $attempt,
        string $status,
        ?string $gatewayStatus,
    ): PaymentReconciliationRun {
        return PaymentReconciliationRun::query()->create([
            'payment_attempt_id' => $attempt->id,
            'attempt_number' => 1,
            'status' => $status,
            'gateway_status' => $gatewayStatus,
            'verified_at' => $status === PaymentReconciliationRun::StatusPaid ? now() : null,
            'finished_at' => now(),
            'idempotency_key' => 'reconciliation:'.$attempt->id,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }
}
