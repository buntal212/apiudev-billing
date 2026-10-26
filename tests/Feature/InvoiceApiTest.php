<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\CustomerReferral;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_valid_payload_creates_an_invoice_with_the_plan_price_snapshot_and_returns_201(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create([
            'application_id' => $application->id,
            'price' => '125000.00',
            'duration_months' => 3,
            'duration_days' => 90,
        ]);
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $response = $this->postJson(
            '/api/v1/admin/invoices',
            $this->invoicePayload($customer, $plan, '2026-10-20 10:00:00'),
            ['Idempotency-Key' => 'invoice-create-0001'],
        )
            ->assertCreated()
            ->assertJsonPath('data.application_customer_id', $customer->id)
            ->assertJsonPath('data.subscription_plan_id', $plan->id)
            ->assertJsonPath('data.amount', '125000.00')
            ->assertJsonPath('data.duration_days', 90)
            ->assertJsonPath('data.status', Invoice::StatusPending);

        $invoiceId = $response->json('data.id');
        $this->assertDatabaseHas('invoices', [
            'id' => $invoiceId,
            'amount' => '125000.00',
            'duration_months' => 3,
            'duration_days' => 90,
            'currency' => 'IDR',
            'idempotency_key' => 'invoice-create-0001',
        ]);
    }

    public function test_returns_422_when_the_plan_belongs_to_a_different_application(): void
    {
        $customer = ApplicationCustomer::factory()->create();
        $plan = SubscriptionPlan::factory()->create([
            'application_id' => Application::factory()->create()->id,
        ]);
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->postJson(
            '/api/v1/admin/invoices',
            $this->invoicePayload($customer, $plan, now()->addWeek()->toDateTimeString()),
            ['Idempotency-Key' => 'invoice-app-mismatch'],
        )
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.subscription_plan_id.0',
                'Paket langganan harus berasal dari aplikasi yang sama dengan pelanggan.',
            );
    }

    public function test_returns_422_when_the_plan_is_inactive(): void
    {
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create([
            'application_id' => $application->id,
            'is_active' => false,
        ]);
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->postJson(
            '/api/v1/admin/invoices',
            $this->invoicePayload($customer, $plan, now()->addWeek()->toDateTimeString()),
            ['Idempotency-Key' => 'invoice-inactive-plan'],
        )
            ->assertUnprocessable()
            ->assertJsonPath('errors.subscription_plan_id.0', 'Paket langganan yang dipilih tidak aktif.');
    }

    public function test_repeating_an_invoice_request_with_the_same_idempotency_key_returns_the_existing_invoice(): void
    {
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id]);
        $payload = $this->invoicePayload($customer, $plan, now()->addWeek()->toDateTimeString());
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $first = $this->postJson('/api/v1/admin/invoices', $payload, [
            'Idempotency-Key' => 'invoice-idempotent-0001',
        ])->assertCreated();
        $second = $this->postJson('/api/v1/admin/invoices', $payload, [
            'Idempotency-Key' => 'invoice-idempotent-0001',
        ])->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_returns_422_for_a_duplicate_pending_invoice_with_a_different_idempotency_key(): void
    {
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id]);
        $payload = $this->invoicePayload($customer, $plan, now()->addWeek()->toDateTimeString());
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->postJson('/api/v1/admin/invoices', $payload, [
            'Idempotency-Key' => 'invoice-duplicate-0001',
        ])->assertCreated();
        $this->postJson('/api/v1/admin/invoices', $payload, [
            'Idempotency-Key' => 'invoice-duplicate-0002',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.invoice.0', 'Tagihan pending untuk kewajiban yang sama sudah tersedia.');
    }

    public function test_returns_422_when_the_client_sends_an_amount(): void
    {
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id]);
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->postJson('/api/v1/admin/invoices', [
            ...$this->invoicePayload($customer, $plan, now()->addWeek()->toDateTimeString()),
            'amount' => '1.00',
        ], ['Idempotency-Key' => 'invoice-amount-not-allowed'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'Nominal tagihan ditentukan berdasarkan paket langganan.');
    }

    public function test_admin_can_list_filter_and_show_invoices(): void
    {
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id]);
        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
        ]);
        Invoice::factory()->create();
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->getJson("/api/v1/admin/invoices?application_id={$application->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $invoice->id);

        $this->getJson("/api/v1/admin/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonPath('data.plan.id', $plan->id);
    }

    public function test_invoice_pricing_snapshot_and_referral_name_are_returned(): void
    {
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id]);
        $partner = Partner::factory()->create(['name' => 'Mitra Andalan']);
        CustomerReferral::factory()->create([
            'application_id' => $application->id,
            'application_customer_id' => $customer->id,
            'partner_id' => $partner->id,
        ]);
        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '100000.00',
            'metadata' => [
                'normal_price' => '175000.00',
                'referral_discount' => '75000.00',
                'final_price' => '100000.00',
                'referral_code' => 'REF-ABC123',
                'referral_partner_name' => 'Mitra Andalan',
            ],
        ]);
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->getJson("/api/v1/admin/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.pricing.normal_price', '175000.00')
            ->assertJsonPath('data.pricing.discount_amount', '75000.00')
            ->assertJsonPath('data.pricing.final_price', '100000.00')
            ->assertJsonPath('data.pricing.referral_code', 'REF-ABC123')
            ->assertJsonPath('data.pricing.referral_partner_name', 'Mitra Andalan');
    }

    public function test_returns_401_when_no_sanctum_token_is_provided(): void
    {
        $this->getJson('/api/v1/admin/invoices')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Sesi Anda telah berakhir. Silakan masuk kembali.');
    }

    public function test_returns_403_when_authenticated_user_is_not_an_admin(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['admin:access']);

        $this->getJson('/api/v1/admin/invoices')
            ->assertForbidden()
            ->assertJsonPath('message', 'Anda tidak memiliki izin untuk mengakses halaman ini.');
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayload(
        ApplicationCustomer $customer,
        SubscriptionPlan $plan,
        string $dueAt,
    ): array {
        return [
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'due_at' => $dueAt,
        ];
    }
}
