<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\ApplicationPaymentGateway;
use App\Models\Invoice;
use App\Models\MidtransGatewayAudit;
use App\Models\MidtransMerchantCredential;
use App\Models\MidtransPaymentMethodFee;
use App\Models\PaymentAllocation;
use App\Models\PaymentAttempt;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\InvoiceSettlementService;
use App\Services\MidtransFeeService;
use App\Services\MidtransGatewayProvisioningClient;
use App\Services\MidtransSecretStore;
use App\Services\VerifiedGatewayPayment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MidtransPaymentMethodFeeTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'is_admin' => true,
        ]);
    }

    public function test_index_fees_auto_seeds_defaults_and_returns_paginated_list(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/admin/midtrans-gateway/fees?merchant_code=udev-central');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'status',
                'data' => [
                    '*' => [
                        'id',
                        'merchant_code',
                        'payment_method',
                        'payment_method_name',
                        'fee_type',
                        'fee_rate',
                        'fixed_amount',
                        'is_active',
                    ],
                ],
                'meta' => ['current_page', 'total', 'per_page', 'has_more'],
            ]);

        $this->assertDatabaseHas('midtrans_payment_method_fees', [
            'merchant_code' => 'udev-central',
            'payment_method' => 'qris',
            'fee_type' => 'percent',
        ]);

        $this->assertDatabaseHas('midtrans_payment_method_fees', [
            'merchant_code' => 'udev-central',
            'payment_method' => 'va_bca',
            'fee_type' => 'fixed',
        ]);
    }

    public function test_store_fee_creates_new_rule_and_records_audit(): void
    {
        $payload = [
            'merchant_code' => 'udev-central',
            'payment_method' => 'shopeepay',
            'payment_method_name' => 'ShopeePay Sandbox',
            'fee_type' => 'percent',
            'fee_rate' => 1.5,
            'fixed_amount' => 0,
            'min_fee' => 1000,
            'max_fee' => 10000,
            'is_active' => true,
            'notes' => 'ShopeePay test rate',
        ];

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/admin/midtrans-gateway/fees', $payload);

        $response->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.payment_method', 'shopeepay');

        $this->assertDatabaseHas('midtrans_payment_method_fees', [
            'merchant_code' => 'udev-central',
            'payment_method' => 'shopeepay',
            'fee_rate' => 1.5000,
        ]);

        $this->assertDatabaseHas('midtrans_gateway_audits', [
            'merchant_code' => 'udev-central',
            'action' => 'fee.created',
            'status' => 'success',
        ]);
    }

    public function test_store_fee_prevents_duplicate_method_for_same_merchant(): void
    {
        MidtransPaymentMethodFee::query()->create([
            'merchant_code' => 'udev-central',
            'payment_method' => 'qris',
            'payment_method_name' => 'QRIS Existing',
            'fee_type' => 'percent',
            'fee_rate' => 0.7,
            'fixed_amount' => 0,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/admin/midtrans-gateway/fees', [
                'merchant_code' => 'udev-central',
                'payment_method' => 'qris',
                'payment_method_name' => 'QRIS Duplicate',
                'fee_type' => 'percent',
                'fee_rate' => 0.8,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['payment_method']);
    }

    public function test_update_fee_modifies_configuration_and_records_audit(): void
    {
        $fee = MidtransPaymentMethodFee::query()->create([
            'merchant_code' => 'udev-central',
            'payment_method' => 'va_bca',
            'payment_method_name' => 'BCA Virtual Account',
            'fee_type' => 'fixed',
            'fixed_amount' => 4000.00,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/v1/admin/midtrans-gateway/fees/{$fee->id}", [
                'fixed_amount' => 4500.00,
                'notes' => 'Naik biaya admin BCA',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.fixed_amount', '4500.00');

        $this->assertDatabaseHas('midtrans_payment_method_fees', [
            'id' => $fee->id,
            'fixed_amount' => 4500.00,
            'notes' => 'Naik biaya admin BCA',
        ]);

        $this->assertDatabaseHas('midtrans_gateway_audits', [
            'merchant_code' => 'udev-central',
            'action' => 'fee.updated',
            'status' => 'success',
        ]);
    }

    public function test_toggle_fee_status(): void
    {
        $fee = MidtransPaymentMethodFee::query()->create([
            'merchant_code' => 'udev-central',
            'payment_method' => 'va_bri',
            'payment_method_name' => 'BRI VA',
            'fee_type' => 'fixed',
            'fixed_amount' => 4000.00,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->patchJson("/api/v1/admin/midtrans-gateway/fees/{$fee->id}/status", [
                'is_active' => false,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('midtrans_payment_method_fees', [
            'id' => $fee->id,
            'is_active' => false,
        ]);
    }

    public function test_destroy_fee_deletes_record_and_records_audit(): void
    {
        $fee = MidtransPaymentMethodFee::query()->create([
            'merchant_code' => 'udev-central',
            'payment_method' => 'test_method',
            'payment_method_name' => 'Test Method',
            'fee_type' => 'fixed',
            'fixed_amount' => 1000.00,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->deleteJson("/api/v1/admin/midtrans-gateway/fees/{$fee->id}");

        $response->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseMissing('midtrans_payment_method_fees', [
            'id' => $fee->id,
        ]);

        $this->assertDatabaseHas('midtrans_gateway_audits', [
            'merchant_code' => 'udev-central',
            'action' => 'fee.deleted',
            'status' => 'success',
        ]);
    }

    public function test_calculate_fee_preview(): void
    {
        MidtransPaymentMethodFee::query()->create([
            'merchant_code' => 'udev-central',
            'payment_method' => 'qris',
            'payment_method_name' => 'QRIS Midtrans',
            'fee_type' => 'percent',
            'fee_rate' => 0.7000,
            'fixed_amount' => 0.00,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/admin/midtrans-gateway/calculate-fee', [
                'merchant_code' => 'udev-central',
                'payment_method' => 'qris',
                'amount' => 100000,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.fee_amount', 700)
            ->assertJsonPath('data.subtotal_amount', 100000)
            ->assertJsonPath('data.total_amount', 100700);
    }

    public function test_checkout_midtrans_calculates_admin_fee_and_saves_snapshot(): void
    {
        $application = Application::factory()->create();
        $plan = SubscriptionPlan::factory()->create([
            'application_id' => $application->id,
            'price' => 100000,
            'duration_days' => 30,
        ]);
        $customer = ApplicationCustomer::factory()->create([
            'application_id' => $application->id,
        ]);

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-TEST-FEE-001',
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => 100000,
            'currency' => 'IDR',
            'duration_months' => 1,
            'duration_days' => 30,
            'idempotency_key' => 'inv-fee-001',
            'status' => Invoice::StatusPending,
            'issued_at' => now(),
            'due_at' => now()->addDays(7),
            'metadata' => [
                'normal_price' => '100000.00',
                'referral_discount' => '0.00',
                'final_price' => '100000.00',
            ],
        ]);

        ApplicationPaymentGateway::query()->create([
            'application_id' => $application->id,
            'provider' => 'midtrans',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'udev-central',
            'is_active' => true,
        ]);

        $secretStore = app(MidtransSecretStore::class);
        $encrypted = $secretStore->encryptSecret('SB-Mid-server-TEST123456', 'udev-central', 'server_key');

        MidtransMerchantCredential::query()->create([
            'merchant_code' => 'udev-central',
            'merchant_name' => 'UDEV Central Billing',
            'environment' => 'sandbox',
            'is_active' => true,
            'encrypted_server_key' => $encrypted['ciphertext'],
            'server_key_nonce' => $encrypted['nonce'],
            'server_key_tag' => $encrypted['tag'],
            'key_version' => $encrypted['key_version'],
            'client_key' => 'SB-Mid-client-TEST123456',
        ]);

        MidtransPaymentMethodFee::query()->create([
            'merchant_code' => 'udev-central',
            'payment_method' => 'qris',
            'payment_method_name' => 'QRIS Midtrans',
            'fee_type' => 'percent',
            'fee_rate' => 0.7000,
            'fixed_amount' => 0.00,
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/INV-TEST-FEE-001-A1/status' => Http::response([
                'status_code' => '404',
                'status_message' => 'Transaction does not exist',
            ], 404),
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'status_message' => 'QRIS transaction is created',
                'transaction_id' => 'midtrans-tx-fee-001',
                'order_id' => 'INV-TEST-FEE-001-A1',
                'gross_amount' => '100700.00',
                'payment_type' => 'qris',
                'transaction_status' => 'pending',
                'actions' => [
                    [
                        'name' => 'generate-qr-code',
                        'method' => 'GET',
                        'url' => 'https://api.sandbox.midtrans.com/v2/qris/midtrans-tx-fee-001/qr-code',
                    ],
                ],
            ], 201),
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
                'idempotency_key' => 'idemp-fee-001',
                'provider' => 'midtrans',
                'payment_method' => 'qris',
            ]);

        $response->assertOk();

        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->firstOrFail();

        // 100,000 + 0.7% (700) = 100,700
        $this->assertEquals('100700.00', $attempt->amount);

        // Snapshot is preserved in metadata
        $metadata = $attempt->metadata;
        $this->assertArrayHasKey('fee_breakdown', $metadata);
        $snapshot = $metadata['fee_breakdown'];

        $this->assertEquals(100000, $snapshot['subtotal_amount']);
        $this->assertEquals(700, $snapshot['fee_amount']);
        $this->assertEquals(100700, $snapshot['total_amount']);
        $this->assertEquals('qris', $snapshot['payment_method']);
        $this->assertEquals('percent', $snapshot['fee_type']);
        $this->assertEquals(0.7, $snapshot['fee_rate']);

        // Historical immutability test: modifying fee configuration does NOT mutate the snapshot
        MidtransPaymentMethodFee::query()
            ->where('payment_method', 'qris')
            ->update(['fee_rate' => 1.5]);

        $attempt->refresh();
        $this->assertEquals('100700.00', $attempt->amount);
        $this->assertEquals(700, $attempt->metadata['fee_breakdown']['fee_amount']);
    }

    public function test_settlement_with_admin_fee_allocates_only_package_amount_without_inflating_subscription(): void
    {
        $application = Application::factory()->create();
        $plan = SubscriptionPlan::factory()->create([
            'application_id' => $application->id,
            'price' => 100000,
            'duration_days' => 30,
        ]);
        $customer = ApplicationCustomer::factory()->create([
            'application_id' => $application->id,
        ]);

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-SETTLE-FEE-001',
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => 100000,
            'currency' => 'IDR',
            'duration_months' => 1,
            'duration_days' => 30,
            'idempotency_key' => 'inv-settle-001',
            'status' => Invoice::StatusPending,
            'issued_at' => now(),
            'due_at' => now()->addDays(7),
            'metadata' => [
                'normal_price' => '100000.00',
                'referral_discount' => '0.00',
                'final_price' => '100000.00',
            ],
        ]);

        ApplicationPaymentGateway::query()->create([
            'application_id' => $application->id,
            'provider' => 'midtrans',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'udev-central',
            'is_active' => true,
        ]);

        $feeSnapshot = [
            'package_name' => $plan->name,
            'base_price' => 100000,
            'discount_amount' => 0,
            'subtotal_amount' => 100000,
            'payment_method' => 'qris',
            'payment_method_label' => 'QRIS Midtrans',
            'fee_type' => 'percent',
            'fee_rate' => 0.7,
            'fee_amount' => 700,
            'total_amount' => 100700,
            'currency' => 'IDR',
        ];

        // Attempt amount includes admin fee (100,700)
        $attempt = PaymentAttempt::query()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'provider' => 'midtrans',
            'merchant_reference' => 'INV-SETTLE-FEE-001-A1',
            'gateway_payment_id' => 'midtrans-tx-999',
            'gateway_merchant_code' => 'udev-central',
            'gateway_environment' => 'sandbox',
            'amount' => 100700,
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusSucceeded,
            'idempotency_key' => 'checkout:'.$invoice->id.':test-idemp',
            'requested_at' => now(),
            'completed_at' => now(),
            'metadata' => [
                'payment_method' => 'qris',
                'fee_breakdown' => $feeSnapshot,
            ],
        ]);

        // Verification from Midtrans has the gross amount 100,700
        $verification = new VerifiedGatewayPayment(
            provider: 'midtrans',
            merchantCode: 'udev-central',
            environment: 'sandbox',
            gatewayPaymentId: 'midtrans-tx-999',
            merchantOrderId: 'INV-SETTLE-FEE-001-A1',
            amountMinor: 100700,
            currency: 'IDR',
            paidAt: CarbonImmutable::now(),
        );

        $settlementService = app(InvoiceSettlementService::class);
        $result = $settlementService->settle($attempt, $verification);

        $this->assertEquals(Invoice::StatusPaid, $result->invoice->status);
        $this->assertNotNull($result->invoice->paid_at);

        // Crucial test: PaymentAllocation must record ONLY the package subtotal amount (100,000), NOT 100,700!
        $allocation = PaymentAllocation::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertEquals('100000.00', $allocation->amount);

        // Subscription activated for exactly 30 days based on plan, not inflated by fee
        $subscription = $result->invoice->subscription;
        $this->assertNotNull($subscription);
        $this->assertEquals('active', $subscription->status);
        $this->assertEquals($customer->id, $subscription->application_customer_id);
    }
}
