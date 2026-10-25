<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\ApplicationIntegrationToken;
use App\Models\ApplicationPaymentGateway;
use App\Models\CustomerReferral;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationBillingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.dana_gateway', [
            'environment' => 'sandbox',
            'base_urls' => ['sandbox' => 'https://gateway.test'],
            'timeout' => 8,
            'connect_timeout' => 3,
            'merchant_api_keys' => ['sandbox' => ['udev-demo' => 'test-api-key']],
            'merchants' => ['sandbox' => ['udev-demo' => ['name' => 'UDEV Demo', 'is_active' => true]]],
        ]);
    }

    public function test_global_token_can_preview_and_create_an_idempotent_invoice_with_locked_referral_price(): void
    {
        [$application, $token] = $this->integrationToken();
        $customer = ApplicationCustomer::factory()->create([
            'application_id' => $application->id,
            'external_customer_id' => 'SALON-001',
            'source' => 'integration',
        ]);
        $currentPlan = SubscriptionPlan::factory()->create(['application_id' => $application->id]);
        $targetPlan = SubscriptionPlan::factory()->create([
            'application_id' => $application->id,
            'code' => 'LARIS-30',
            'price' => '175000.00',
            'duration_days' => 30,
        ]);
        $subscription = Subscription::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $currentPlan->id,
            'expires_at' => now()->addDays(10),
        ]);
        CustomerReferral::factory()->create([
            'application_id' => $application->id,
            'application_customer_id' => $customer->id,
            'referral_code' => 'REF-A1B2C3',
            'referral_type' => 'umum',
            'base_price' => '175000.00',
            'discount_amount' => '50000.00',
            'customer_price' => '125000.00',
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/integrations/customers/SALON-001/invoice-preview', [
                'subscription_plan_code' => $targetPlan->code,
            ])
            ->assertOk()
            ->assertJsonPath('data.pricing.normal_price', '175000.00')
            ->assertJsonPath('data.pricing.discount_amount', '50000.00')
            ->assertJsonPath('data.pricing.final_price', '125000.00');

        $payload = [
            'subscription_plan_code' => $targetPlan->code,
            'due_at' => now()->addDay()->toISOString(),
        ];
        $headers = ['Idempotency-Key' => 'larissama-invoice-salon-001'];

        $this->withToken($token)
            ->postJson('/api/v1/integrations/customers/SALON-001/invoices', $payload, $headers)
            ->assertCreated()
            ->assertJsonPath('data.plan.code', $targetPlan->code)
            ->assertJsonPath('data.pricing.final_price', '125000.00');

        $this->withToken($token)
            ->postJson('/api/v1/integrations/customers/SALON-001/invoices', $payload, $headers)
            ->assertCreated();

        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseHas('invoices', [
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $targetPlan->id,
            'subscription_id' => $subscription->id,
            'amount' => '125000.00',
        ]);
    }

    public function test_billing_endpoints_isolate_invoices_and_idempotency_keys_between_applications(): void
    {
        [$firstApplication, $firstToken] = $this->integrationToken();
        [$secondApplication, $secondToken] = $this->integrationToken();
        $firstCustomer = ApplicationCustomer::factory()->create([
            'application_id' => $firstApplication->id,
            'external_customer_id' => 'SAME-CUSTOMER',
        ]);
        $secondCustomer = ApplicationCustomer::factory()->create([
            'application_id' => $secondApplication->id,
            'external_customer_id' => 'SAME-CUSTOMER',
        ]);
        $firstPlan = SubscriptionPlan::factory()->create(['application_id' => $firstApplication->id, 'code' => 'FIRST-30']);
        $secondPlan = SubscriptionPlan::factory()->create(['application_id' => $secondApplication->id, 'code' => 'SECOND-30']);
        $headers = ['Idempotency-Key' => 'shared-idempotency-key'];

        $this->withToken($firstToken)
            ->postJson('/api/v1/integrations/customers/SAME-CUSTOMER/invoices', [
                'subscription_plan_code' => $firstPlan->code,
                'due_at' => now()->addDay()->toISOString(),
            ], $headers)
            ->assertCreated();
        $invoice = Invoice::query()->where('application_customer_id', $firstCustomer->id)->sole();

        $this->withToken($secondToken)
            ->getJson("/api/v1/integrations/invoices/{$invoice->invoice_number}")
            ->assertNotFound();
        $this->withToken($secondToken)
            ->postJson('/api/v1/integrations/customers/SAME-CUSTOMER/invoices', [
                'subscription_plan_code' => $secondPlan->code,
                'due_at' => now()->addDay()->toISOString(),
            ], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertSame($secondCustomer->id, ApplicationCustomer::query()->findOrFail($secondCustomer->id)->id);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_global_token_can_start_sandbox_checkout_and_read_invoice_tracking(): void
    {
        [$application, $token] = $this->integrationToken();
        $customer = ApplicationCustomer::factory()->create([
            'application_id' => $application->id,
            'external_customer_id' => 'SALON-CHECKOUT',
        ]);
        $plan = SubscriptionPlan::factory()->create([
            'application_id' => $application->id,
            'code' => 'CHECKOUT-30',
            'price' => '10000.00',
        ]);
        ApplicationPaymentGateway::query()->create([
            'application_id' => $application->id,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'udev-demo',
            'is_active' => true,
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/integrations/customers/SALON-CHECKOUT/invoices', [
                'subscription_plan_code' => $plan->code,
                'due_at' => now()->addDay()->toISOString(),
            ], ['Idempotency-Key' => 'checkout-invoice-create'])
            ->assertCreated();
        $invoice = Invoice::query()->where('application_customer_id', $customer->id)->sole();

        Http::fake(function (HttpRequest $request): mixed {
            if ($request->method() === 'GET') {
                return Http::response(['data' => []]);
            }

            $attempt = PaymentAttempt::query()->sole();

            return Http::response(['data' => [
                'id' => 'dana-sandbox-payment-001',
                'merchant_order_id' => $attempt->merchant_reference,
                'amount_minor' => 10000,
                'currency' => 'IDR',
                'status' => 'pending',
                'checkout_url' => 'https://checkout.test/session/001',
                'expires_at' => now()->addMinutes(30)->toISOString(),
            ]]);
        });

        $this->withToken($token)
            ->postJson("/api/v1/integrations/invoices/{$invoice->invoice_number}/checkout", [], [
                'Idempotency-Key' => 'checkout-invoice-start',
            ])
            ->assertOk()
            ->assertJsonPath('data.payment.provider', 'dana')
            ->assertJsonPath('data.payment.checkout_status', 'ready')
            ->assertJsonPath('data.payment.checkout_url', 'https://checkout.test/session/001');

        $this->withToken($token)
            ->getJson("/api/v1/integrations/invoices/{$invoice->invoice_number}")
            ->assertOk()
            ->assertJsonPath('data.status', Invoice::StatusPending)
            ->assertJsonPath('data.payment.transaction_id', $invoice->invoice_number.'-A1');
        $this->withToken($token)
            ->getJson('/api/v1/integrations/customers/SALON-CHECKOUT/transactions')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.invoice_number', $invoice->invoice_number);
    }

    /** @return array{0: Application, 1: string} */
    private function integrationToken(): array
    {
        $application = Application::factory()->create();
        $plaintext = 'udev_global_'.fake()->uuid();
        ApplicationIntegrationToken::factory()->create([
            'application_id' => $application->id,
            'token_kind' => ApplicationIntegrationToken::KindGlobal,
            'token_hash' => hash('sha256', $plaintext),
        ]);

        return [$application, $plaintext];
    }
}
