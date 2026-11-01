<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\ApplicationPaymentGateway;
use App\Models\CheckoutOutboxMessage;
use App\Models\Invoice;
use App\Models\MidtransMerchantCredential;
use App\Models\PaymentAllocation;
use App\Models\PaymentAttempt;
use App\Models\SettlementEvent;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\MidtransSecretStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MidtransInvoiceCheckoutAndSettlementTest extends TestCase
{
    use RefreshDatabase;

    private const string TestServerKey = 'SB-Mid-server-TESTKEY1234567890';
    private const string MerchantCode = 'larissama';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.midtrans_gateway', [
            'sandbox_base_url' => 'https://api.sandbox.midtrans.com/v2',
            'gateway_service_url' => '',
            'service_token' => 'test-service-token-123456',
            'timeout' => 5,
            'connect_timeout' => 3,
        ]);
    }

    public function test_invoice_can_checkout_through_midtrans_using_application_mapping(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'status_message' => 'QRIS transaction is created',
                'transaction_id' => 'midtrans-tx-001',
                'order_id' => $invoice->invoice_number.'-A1',
                'gross_amount' => '50000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'pending',
                'qr_string' => '00020101021226590014ID.LINKAJA.WWW0118936009110022201980020300051440014ID.GO-PAY.WWW0215ID10200210081700303UMI5204581253033605405500005802ID5914UDEV Midtrans6007JAKARTA61051234062070703A0163041D3A',
                'actions' => [
                    [
                        'name' => 'generate-qr-code',
                        'method' => 'GET',
                        'url' => 'https://api.sandbox.midtrans.com/v2/qris/midtrans-tx-001/qr-code',
                    ],
                ],
            ], 201),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        $response = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'qris',
        ], ['Idempotency-Key' => 'idemp-midtrans-chk-001']);

        $response->assertOk()
            ->assertJsonPath('data.invoice_id', $invoice->id)
            ->assertJsonPath('data.gateway_payment_id', 'midtrans-tx-001')
            ->assertJsonPath('data.payment_method', 'qris')
            ->assertJsonPath('data.checkout_status', 'ready')
            ->assertJsonPath('data.status', PaymentAttempt::StatusPending);

        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame('midtrans', $attempt->provider);
        $this->assertSame(self::MerchantCode, $attempt->gateway_merchant_code);
        $this->assertSame('sandbox', $attempt->gateway_environment);
        $this->assertSame('midtrans-tx-001', $attempt->gateway_payment_id);
        $this->assertSame($invoice->invoice_number.'-A1', $attempt->merchant_reference);
        $this->assertNotEmpty($attempt->metadata['qr_content']);
        $this->assertSame('qris_mpm', $attempt->metadata['payment_instruction']['type']);

        // Invoice remains pending until settlement
        $this->assertSame(Invoice::StatusPending, $invoice->fresh()->status);
    }

    public function test_invoice_checkout_supports_va_bank_transfer_and_echannel(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'status_message' => 'Mandiri Bill transaction is created',
                'transaction_id' => 'midtrans-tx-mandiri',
                'order_id' => $invoice->invoice_number.'-A1',
                'gross_amount' => '50000.00',
                'payment_type' => 'echannel',
                'transaction_status' => 'pending',
                'bill_key' => '9988776655',
                'biller_code' => '70012',
            ], 201),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        $response = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'bank_transfer',
            'bank' => 'mandiri',
        ], ['Idempotency-Key' => 'idemp-midtrans-va-mandiri']);

        $response->assertOk()
            ->assertJsonPath('data.payment_code', '9988776655')
            ->assertJsonPath('data.payment_instruction.type', 'virtual_account')
            ->assertJsonPath('data.payment_instruction.bill_key', '9988776655')
            ->assertJsonPath('data.payment_instruction.biller_code', '70012');

        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame('9988776655', $attempt->metadata['payment_code']);
        $this->assertSame('70012', $attempt->metadata['biller_code']);
    }

    public function test_midtrans_webhook_verifies_signature_and_settles_invoice_and_activates_subscription(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        $attempt = PaymentAttempt::query()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'provider' => 'midtrans',
            'merchant_reference' => $invoice->invoice_number.'-A1',
            'gateway_merchant_code' => self::MerchantCode,
            'gateway_environment' => 'sandbox',
            'amount' => $invoice->amount,
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusPending,
            'gateway_payment_id' => 'midtrans-tx-12345',
            'idempotency_key' => 'checkout:'.$invoice->id.':init',
            'requested_at' => now(),
            'metadata' => [
                'order_id' => $invoice->invoice_number.'-A1',
                'payment_method' => 'qris',
            ],
        ]);

        $orderId = $attempt->merchant_reference;
        $statusCode = '200';
        $grossAmount = '50000.00';
        $signature = hash('sha512', $orderId.$statusCode.$grossAmount.self::TestServerKey);

        $payload = [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'transaction_id' => 'midtrans-tx-12345',
            'payment_type' => 'qris',
            'settlement_time' => now()->format('Y-m-d H:i:s'),
        ];

        $response = $this->postJson('/api/v1/payment-gateways/midtrans/webhook', $payload);

        $response->assertOk()
            ->assertJsonPath('status', 'OK')
            ->assertJsonPath('transaction_status', 'settlement');

        $attempt->refresh();
        $invoice->refresh();

        $this->assertSame(PaymentAttempt::StatusSucceeded, $attempt->status);
        $this->assertSame(Invoice::StatusPaid, $invoice->status);
        $this->assertNotNull($invoice->paid_at);

        $this->assertDatabaseHas('payment_allocations', [
            'invoice_id' => $invoice->id,
            'payment_attempt_id' => $attempt->id,
        ]);

        $subscription = Subscription::query()->where('application_customer_id', $invoice->application_customer_id)->firstOrFail();
        $this->assertSame(Subscription::StatusActive, $subscription->status);
        $this->assertNotNull($subscription->expires_at);
        $this->assertEquals($invoice->duration_days, CarbonImmutable::parse($invoice->paid_at)->diffInDays($subscription->expires_at));
    }

    public function test_midtrans_webhook_idempotency_prevents_duplicate_settlement(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        $attempt = PaymentAttempt::query()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'provider' => 'midtrans',
            'merchant_reference' => $invoice->invoice_number.'-A1',
            'gateway_merchant_code' => self::MerchantCode,
            'gateway_environment' => 'sandbox',
            'amount' => $invoice->amount,
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusPending,
            'gateway_payment_id' => 'midtrans-tx-dup-test',
            'idempotency_key' => 'checkout:'.$invoice->id.':init',
            'requested_at' => now(),
        ]);

        $orderId = $attempt->merchant_reference;
        $statusCode = '200';
        $grossAmount = '50000.00';
        $signature = hash('sha512', $orderId.$statusCode.$grossAmount.self::TestServerKey);

        $payload = [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'transaction_id' => 'midtrans-tx-dup-test',
            'settlement_time' => now()->format('Y-m-d H:i:s'),
        ];

        // First webhook
        $this->postJson('/api/v1/payment-gateways/midtrans/webhook', $payload)->assertOk();
        $firstExpiry = Subscription::query()->where('application_customer_id', $invoice->application_customer_id)->value('expires_at');

        // Duplicate webhook
        $response = $this->postJson('/api/v1/payment-gateways/midtrans/webhook', $payload);
        $response->assertOk();

        // Ensure allocations and subscriptions were not duplicated
        $this->assertSame(1, PaymentAllocation::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame(1, SettlementEvent::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame(1, Subscription::query()->where('application_customer_id', $invoice->application_customer_id)->count());
        $this->assertEquals($firstExpiry, Subscription::query()->where('application_customer_id', $invoice->application_customer_id)->value('expires_at'));
    }

    public function test_midtrans_webhook_renewal_preserves_remaining_active_days(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        // Customer already has active subscription expiring in 20 days
        $existingExpiresAt = now()->toImmutable()->addDays(20);
        $existingSub = Subscription::query()->create([
            'application_customer_id' => $invoice->application_customer_id,
            'subscription_plan_id' => $invoice->subscription_plan_id,
            'starts_at' => now()->subDays(10),
            'expires_at' => $existingExpiresAt,
            'status' => Subscription::StatusActive,
        ]);

        $attempt = PaymentAttempt::query()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'provider' => 'midtrans',
            'merchant_reference' => $invoice->invoice_number.'-A1',
            'gateway_merchant_code' => self::MerchantCode,
            'gateway_environment' => 'sandbox',
            'amount' => $invoice->amount,
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusPending,
            'gateway_payment_id' => 'midtrans-tx-renew',
            'idempotency_key' => 'checkout:'.$invoice->id.':init',
            'requested_at' => now(),
        ]);

        $orderId = $attempt->merchant_reference;
        $statusCode = '200';
        $grossAmount = '50000.00';
        $signature = hash('sha512', $orderId.$statusCode.$grossAmount.self::TestServerKey);

        $payload = [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'transaction_id' => 'midtrans-tx-renew',
            'settlement_time' => now()->format('Y-m-d H:i:s'),
        ];

        $this->postJson('/api/v1/payment-gateways/midtrans/webhook', $payload)->assertOk();

        $existingSub->refresh();
        // New expiry must be existingExpiresAt + 30 days = 50 days from now (remaining 20 days not lost!)
        $expectedNewExpiresAt = $existingExpiresAt->addDays($invoice->duration_days);
        $this->assertSame($expectedNewExpiresAt->timestamp, $existingSub->expires_at->timestamp);
    }

    public function test_midtrans_webhook_terminal_statuses_do_not_touch_subscription(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        $existingExpiresAt = now()->toImmutable()->addDays(15);
        $existingSub = Subscription::query()->create([
            'application_customer_id' => $invoice->application_customer_id,
            'subscription_plan_id' => $invoice->subscription_plan_id,
            'starts_at' => now()->subDays(15),
            'expires_at' => $existingExpiresAt,
            'status' => Subscription::StatusActive,
        ]);

        $attempt = PaymentAttempt::query()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'provider' => 'midtrans',
            'merchant_reference' => $invoice->invoice_number.'-A1',
            'gateway_merchant_code' => self::MerchantCode,
            'gateway_environment' => 'sandbox',
            'amount' => $invoice->amount,
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusPending,
            'gateway_payment_id' => 'midtrans-tx-expire',
            'idempotency_key' => 'checkout:'.$invoice->id.':init',
            'requested_at' => now(),
        ]);

        $orderId = $attempt->merchant_reference;
        $statusCode = '200';
        $grossAmount = '50000.00';
        $signature = hash('sha512', $orderId.$statusCode.$grossAmount.self::TestServerKey);

        $payload = [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signature,
            'transaction_status' => 'expire',
            'transaction_id' => 'midtrans-tx-expire',
        ];

        $this->postJson('/api/v1/payment-gateways/midtrans/webhook', $payload)->assertOk();

        $attempt->refresh();
        $existingSub->refresh();
        $invoice->refresh();

        $this->assertSame(PaymentAttempt::StatusExpired, $attempt->status);
        $this->assertSame(Invoice::StatusPending, $invoice->status);
        $this->assertSame($existingExpiresAt->timestamp, $existingSub->expires_at->timestamp);
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        $attempt = PaymentAttempt::query()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'provider' => 'midtrans',
            'merchant_reference' => $invoice->invoice_number.'-A1',
            'gateway_merchant_code' => self::MerchantCode,
            'gateway_environment' => 'sandbox',
            'amount' => $invoice->amount,
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusPending,
            'gateway_payment_id' => 'midtrans-tx-bad-sig',
            'idempotency_key' => 'checkout:'.$invoice->id.':init',
            'requested_at' => now(),
        ]);

        $payload = [
            'order_id' => $attempt->merchant_reference,
            'status_code' => '200',
            'gross_amount' => '50000.00',
            'signature_key' => 'invalidsignaturefake512fake512fake512fake512',
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'transaction_id' => 'midtrans-tx-bad-sig',
        ];

        $this->postJson('/api/v1/payment-gateways/midtrans/webhook', $payload)
            ->assertForbidden();

        $this->assertSame(PaymentAttempt::StatusPending, $attempt->fresh()->status);
        $this->assertSame(Invoice::StatusPending, $invoice->fresh()->status);
    }

    public function test_reconciliation_polling_recovers_paid_invoice_when_webhook_delayed(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        $attempt = PaymentAttempt::query()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'provider' => 'midtrans',
            'merchant_reference' => $invoice->invoice_number.'-A1',
            'gateway_merchant_code' => self::MerchantCode,
            'gateway_environment' => 'sandbox',
            'amount' => $invoice->amount,
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusPending,
            'gateway_payment_id' => 'midtrans-tx-reconcile',
            'idempotency_key' => 'checkout:'.$invoice->id.':init',
            'requested_at' => now(),
        ]);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/'.urlencode($attempt->merchant_reference).'/status' => Http::response([
                'status_code' => '200',
                'status_message' => 'Success, transaction found',
                'transaction_id' => 'midtrans-tx-reconcile',
                'order_id' => $attempt->merchant_reference,
                'gross_amount' => '50000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'settlement',
                'fraud_status' => 'accept',
                'settlement_time' => '2026-11-01 12:00:00',
            ], 200),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        $response = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/reconcile");
        $response->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $attempt->refresh();
        $invoice->refresh();

        $this->assertSame(PaymentAttempt::StatusSucceeded, $attempt->status);
        $this->assertSame(Invoice::StatusPaid, $invoice->status);
        $this->assertSame(1, PaymentAllocation::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_merchant_isolation_rejects_settlement_for_unmapped_merchant(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        // Create credential for an isolated third-party merchant not mapped to this application
        $secretStore = app(MidtransSecretStore::class);
        $foreignKey = 'SB-Mid-server-FOREIGNMERCHANT999';
        $encrypted = $secretStore->encryptSecret($foreignKey, 'foreign-merchant', 'server_key');
        MidtransMerchantCredential::create([
            'merchant_code' => 'foreign-merchant',
            'merchant_name' => 'Foreign Merchant Store',
            'environment' => 'sandbox',
            'encrypted_server_key' => $encrypted['ciphertext'],
            'server_key_nonce' => $encrypted['nonce'],
            'server_key_tag' => $encrypted['tag'],
            'key_version' => $encrypted['key_version'],
            'is_active' => true,
        ]);

        $attempt = PaymentAttempt::query()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'provider' => 'midtrans',
            'merchant_reference' => $invoice->invoice_number.'-A1',
            'gateway_merchant_code' => 'foreign-merchant',
            'gateway_environment' => 'sandbox',
            'amount' => $invoice->amount,
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusPending,
            'gateway_payment_id' => 'midtrans-foreign-tx',
            'idempotency_key' => 'checkout:'.$invoice->id.':foreign',
            'requested_at' => now(),
        ]);

        $orderId = $attempt->merchant_reference;
        $statusCode = '200';
        $grossAmount = '50000.00';
        $signature = hash('sha512', $orderId.$statusCode.$grossAmount.$foreignKey);

        $payload = [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'transaction_id' => 'midtrans-foreign-tx',
            'settlement_time' => now()->format('Y-m-d H:i:s'),
        ];

        // Should throw SettlementRejectedException or return 500 because merchant mapping is missing for application
        $response = $this->postJson('/api/v1/payment-gateways/midtrans/webhook', $payload);
        $this->assertNotSame(200, $response->status());

        // Invoice is not paid
        $this->assertSame(Invoice::StatusPending, $invoice->fresh()->status);
        $this->assertDatabaseMissing('payment_allocations', ['invoice_id' => $invoice->id]);
    }

    public function test_checkout_handles_gateway_failure_and_allows_retry(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        // Sequence: fails on initial attempt with 500, recovers on retry with 201
        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::sequence()
                ->push([
                    'status_code' => '500',
                    'status_message' => 'Internal Server Error at Midtrans',
                ], 500)
                ->push([
                    'status_code' => '201',
                    'status_message' => 'QRIS transaction is created',
                    'transaction_id' => 'midtrans-tx-recovered',
                    'order_id' => $invoice->invoice_number.'-A1',
                    'gross_amount' => '50000.00',
                    'payment_type' => 'qris',
                    'transaction_status' => 'pending',
                    'qr_string' => '00020101021226590014ID.LINKAJA.WWW',
                ], 201),
        ]);

        $response = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'qris',
        ], ['Idempotency-Key' => 'idemp-fail-and-retry']);

        $response->assertStatus(502);

        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(PaymentAttempt::StatusInitiated, $attempt->status);

        $retryResponse = $this->postJson("/api/v1/admin/payment-attempts/{$attempt->id}/checkout/retry");
        $retryResponse->assertOk()
            ->assertJsonPath('data.status', PaymentAttempt::StatusPending);

        $this->assertSame('midtrans-tx-recovered', $attempt->fresh()->gateway_payment_id);
    }

    public function test_webhook_accepted_with_internal_service_token(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        $attempt = PaymentAttempt::query()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'provider' => 'midtrans',
            'merchant_reference' => $invoice->invoice_number.'-A1',
            'gateway_merchant_code' => self::MerchantCode,
            'gateway_environment' => 'sandbox',
            'amount' => $invoice->amount,
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusPending,
            'gateway_payment_id' => 'midtrans-service-token-tx',
            'idempotency_key' => 'checkout:'.$invoice->id.':srv',
            'requested_at' => now(),
        ]);

        $payload = [
            'order_id' => $attempt->merchant_reference,
            'status_code' => '200',
            'gross_amount' => '50000.00',
            'signature_key' => 'verified_by_internal_service',
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'transaction_id' => 'midtrans-service-token-tx',
            'settlement_time' => now()->format('Y-m-d H:i:s'),
        ];

        $response = $this->withHeader('Authorization', 'Bearer test-service-token-123456')
            ->postJson('/api/v1/payment-gateways/midtrans/webhook', $payload);

        $response->assertOk()
            ->assertJsonPath('status', 'OK');

        $this->assertSame(Invoice::StatusPaid, $invoice->fresh()->status);
    }

    /**
     * @return array{0: Invoice, 1: MidtransMerchantCredential}
     */
    private function setupInvoiceWithMidtrans(): array
    {
        $app = Application::factory()->create(['name' => 'Aplikasi Larissama']);
        $customer = ApplicationCustomer::factory()->create(['application_id' => $app->id]);
        $plan = SubscriptionPlan::factory()->create([
            'application_id' => $app->id,
            'price' => '50000.00',
            'duration_days' => 30,
        ]);

        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '50000.00',
            'currency' => 'IDR',
            'duration_days' => 30,
            'status' => Invoice::StatusPending,
            'due_at' => now()->addDays(3),
        ]);

        $secretStore = app(MidtransSecretStore::class);
        $encrypted = $secretStore->encryptSecret(self::TestServerKey, self::MerchantCode, 'server_key');

        $credential = MidtransMerchantCredential::create([
            'merchant_code' => self::MerchantCode,
            'merchant_name' => 'Larissama Store',
            'environment' => 'sandbox',
            'encrypted_server_key' => $encrypted['ciphertext'],
            'server_key_nonce' => $encrypted['nonce'],
            'server_key_tag' => $encrypted['tag'],
            'key_version' => $encrypted['key_version'],
            'is_active' => true,
        ]);

        ApplicationPaymentGateway::create([
            'application_id' => $app->id,
            'provider' => 'midtrans',
            'environment' => 'sandbox',
            'gateway_merchant_code' => self::MerchantCode,
            'is_active' => true,
        ]);

        return [$invoice->fresh(['plan', 'customer']), $credential];
    }
}
