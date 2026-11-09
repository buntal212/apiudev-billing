<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\ApplicationPaymentGateway;
use App\Models\CheckoutOutboxMessage;
use App\Models\Invoice;
use App\Models\MidtransMerchantCredential;
use App\Models\MidtransPaymentMethodFee;
use App\Models\PaymentAllocation;
use App\Models\PaymentAttempt;
use App\Models\PaymentReconciliationRun;
use App\Models\SettlementEvent;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\MidtransGatewayProvisioningClient;
use App\Services\MidtransSecretStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    public function test_midtrans_qris_returns_qr_url_and_checkout_url(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'status_message' => 'QRIS transaction is created',
                'transaction_id' => 'tx-midtrans-qris-url-01',
                'order_id' => $invoice->invoice_number.'-A1',
                'gross_amount' => '50000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'pending',
                'actions' => [
                    [
                        'name' => 'generate-qr-code',
                        'method' => 'GET',
                        'url' => 'https://api.sandbox.midtrans.com/v2/qris/tx-midtrans-qris-url-01/qr-code',
                    ],
                ],
                'qr_string' => '00020101021226590014ID.LINKAJA.WWW',
            ], 201),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        $response = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'qris',
            'provider' => 'midtrans',
        ], ['Idempotency-Key' => 'idemp-chk-qris-url-01']);

        $response->assertOk()
            ->assertJsonPath('data.checkout_status', 'ready')
            ->assertJsonPath('data.qr_url', 'https://api.sandbox.midtrans.com/v2/qris/tx-midtrans-qris-url-01/qr-code')
            ->assertJsonPath('data.checkout_url', 'https://api.sandbox.midtrans.com/v2/qris/tx-midtrans-qris-url-01/qr-code');

        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame('tx-midtrans-qris-url-01', $attempt->provider_reference);
        $this->assertSame('https://api.sandbox.midtrans.com/v2/qris/tx-midtrans-qris-url-01/qr-code', $attempt->checkout_url);
        $this->assertSame('https://api.sandbox.midtrans.com/v2/qris/tx-midtrans-qris-url-01/qr-code', $attempt->metadata['qr_url']);
    }

    public function test_midtrans_qris_recovers_when_reconciled_with_missing_qr_url(): void
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
            'gateway_payment_id' => 'tx-missing-recovered-01',
            'provider_reference' => null,
            'checkout_url' => null,
            'idempotency_key' => 'checkout:'.$invoice->id.':rec',
            'requested_at' => now(),
            'metadata' => [
                'payment_method' => 'qris',
                'payment_type' => 'qris',
            ],
        ]);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/'.$attempt->merchant_reference.'/status' => Http::response([
                'status_code' => '201',
                'status_message' => 'Success, transaction found',
                'transaction_id' => 'tx-missing-recovered-01',
                'order_id' => $attempt->merchant_reference,
                'gross_amount' => '50000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'pending',
            ], 200),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        $res = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/reconcile");
        $res->assertOk();

        $refreshed = $attempt->fresh();
        $this->assertSame('tx-missing-recovered-01', $refreshed->provider_reference);
        $this->assertSame('https://api.sandbox.midtrans.com/v2/qris/tx-missing-recovered-01/qr-code', $refreshed->checkout_url);
        $this->assertSame('https://api.sandbox.midtrans.com/v2/qris/tx-missing-recovered-01/qr-code', $refreshed->metadata['qr_url']);
    }

    public function test_midtrans_reconcile_forces_stale_processing_recovery(): void
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
            'gateway_payment_id' => 'tx-stale-01',
            'idempotency_key' => 'checkout:'.$invoice->id.':stale',
            'requested_at' => now(),
            'metadata' => [
                'payment_method' => 'qris',
            ],
        ]);

        // Create a run stuck in processing 20 seconds ago
        $staleRun = PaymentReconciliationRun::query()->create([
            'payment_attempt_id' => $attempt->id,
            'attempt_number' => 1,
            'status' => PaymentReconciliationRun::StatusProcessing,
            'idempotency_key' => 'reconciliation:try:stale-01',
            'started_at' => now()->subSeconds(20),
        ]);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/'.$attempt->merchant_reference.'/status' => Http::response([
                'status_code' => '201',
                'status_message' => 'Success, transaction found',
                'transaction_id' => 'tx-stale-01',
                'order_id' => $attempt->merchant_reference,
                'gross_amount' => '50000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'pending',
            ], 200),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        $res = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/reconcile");
        $res->assertOk();

        // The stale run should have been marked failed due to timeout
        $this->assertSame(PaymentReconciliationRun::StatusFailed, $staleRun->fresh()->status);
        $this->assertSame('reconciliation_stale_timeout', $staleRun->fresh()->error_code);

        // A new run should have been created and succeeded in pending check
        $latestRun = $attempt->latestReconciliationRun()->first();
        $this->assertNotNull($latestRun);
        $this->assertSame(2, $latestRun->attempt_number);
        $this->assertSame(PaymentReconciliationRun::StatusPending, $latestRun->status);
    }

    public function test_midtrans_idempotent_settlement_prevents_duplicate_subscription_extension(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        $subscription = Subscription::factory()->create([
            'application_customer_id' => $invoice->application_customer_id,
            'subscription_plan_id' => $invoice->subscription_plan_id,
            'status' => Subscription::StatusActive,
            'starts_at' => now()->subDays(10),
            'expires_at' => now()->addDays(20),
        ]);
        $invoice->update(['subscription_id' => $subscription->id]);

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
            'gateway_payment_id' => 'tx-dup-settle-01',
            'idempotency_key' => 'checkout:'.$invoice->id.':dup',
            'requested_at' => now(),
        ]);

        $serverKey = self::TestServerKey;
        $orderId = $attempt->merchant_reference;
        $statusCode = '200';
        $grossAmount = '50000.00';
        $signature = hash('sha512', $orderId.$statusCode.$grossAmount.$serverKey);

        $payload = [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'transaction_id' => 'tx-dup-settle-01',
            'settlement_time' => now()->format('Y-m-d H:i:s'),
        ];

        // First webhook call: settles invoice
        $r1 = $this->postJson('/api/v1/payment-gateways/midtrans/webhook', $payload);
        $r1->assertOk();
        $this->assertSame(Invoice::StatusPaid, $invoice->fresh()->status);
        $initialExpiresAt = $subscription->fresh()->expires_at;

        // Second duplicate webhook call: idempotent, no extra extension
        $r2 = $this->postJson('/api/v1/payment-gateways/midtrans/webhook', $payload);
        $r2->assertOk();

        $this->assertEquals($initialExpiresAt, $subscription->fresh()->expires_at);
        $this->assertSame(1, PaymentAllocation::query()->where('payment_attempt_id', $attempt->id)->count());
    }

    public function test_checkout_with_same_idempotency_key_returns_existing_attempt_without_creating_new_attempt(): void
    {
        [$invoice] = $this->setupInvoiceWithMidtrans();

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'status_message' => 'QRIS transaction is created',
                'transaction_id' => 'midtrans-tx-same-idemp-01',
                'order_id' => $invoice->invoice_number.'-A1',
                'gross_amount' => '50000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'pending',
                'qr_string' => '00020101021226590014ID.LINKAJA.WWW0118936009110022201980020300051440014ID.GO-PAY.WWW0215ID10200210081700303UMI5204581253033605405500005802ID5914UDEV Midtrans6007JAKARTA61051234062070703A0163041D3A',
                'actions' => [
                    [
                        'name' => 'generate-qr-code',
                        'method' => 'GET',
                        'url' => 'https://api.sandbox.midtrans.com/v2/qris/midtrans-tx-same-idemp-01/qr-code',
                    ],
                ],
            ], 201),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        // First request with idempotency key
        $res1 = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'qris',
            'provider' => 'midtrans',
        ], ['Idempotency-Key' => 'idemp-midtrans-idempotent-test-01']);
        $res1->assertOk();
        $attemptId1 = $res1->json('data.id');

        // Second request with SAME idempotency key and matching parameters
        $res2 = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'qris',
            'provider' => 'midtrans',
        ], ['Idempotency-Key' => 'idemp-midtrans-idempotent-test-01']);
        $res2->assertOk();
        $attemptId2 = $res2->json('data.id');

        // Exactly the same attempt is returned, no new attempt created
        $this->assertSame($attemptId1, $attemptId2);
        $this->assertSame(1, PaymentAttempt::query()->where('invoice_id', $invoice->id)->count());
        $this->assertCount(1, Http::recorded(fn (Request $request) => str_contains($request->url(), '/charge')));
    }

    public function test_checkout_with_same_idempotency_key_but_different_parameters_returns_409_conflict(): void
    {
        [$invoice] = $this->setupInvoiceWithMidtrans();

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'status_message' => 'QRIS transaction is created',
                'transaction_id' => 'midtrans-tx-conflict-01',
                'order_id' => $invoice->invoice_number.'-A1',
                'gross_amount' => '50000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'pending',
                'qr_string' => '00020101021226590014ID.LINKAJA.WWW0118936009110022201980020300051440014ID.GO-PAY.WWW0215ID10200210081700303UMI5204581253033605405500005802ID5914UDEV Midtrans6007JAKARTA61051234062070703A0163041D3A',
                'actions' => [
                    [
                        'name' => 'generate-qr-code',
                        'method' => 'GET',
                        'url' => 'https://api.sandbox.midtrans.com/v2/qris/midtrans-tx-conflict-01/qr-code',
                    ],
                ],
            ], 201),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        // First request: QRIS
        $res1 = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'qris',
            'provider' => 'midtrans',
        ], ['Idempotency-Key' => 'idemp-midtrans-conflict-test-01']);
        $res1->assertOk();

        // Second request with SAME idempotency key but DIFFERENT parameters (va_bca instead of qris)
        $res2 = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'va_bca',
            'bank' => 'bca',
            'provider' => 'midtrans',
        ], ['Idempotency-Key' => 'idemp-midtrans-conflict-test-01']);

        // Must return HTTP 409 Conflict with informative message
        $res2->assertStatus(409)
            ->assertJsonStructure(['message']);
        $this->assertStringContainsString('parameter berbeda', $res2->json('message'));

        // No new attempt is created; attempt count remains 1
        $this->assertSame(1, PaymentAttempt::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_checkout_switching_methods_with_new_idempotency_key_cancels_previous_and_creates_new_attempt_cleanly(): void
    {
        [$invoice] = $this->setupInvoiceWithMidtrans();

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::sequence()
                ->push([
                    'status_code' => '201',
                    'status_message' => 'QRIS transaction is created',
                    'transaction_id' => 'midtrans-tx-switch-01',
                    'order_id' => $invoice->invoice_number.'-A1',
                    'gross_amount' => '50000.00',
                    'payment_type' => 'qris',
                    'transaction_status' => 'pending',
                    'qr_string' => '00020101021226590014ID.LINKAJA.WWW0118936009110022201980020300051440014ID.GO-PAY.WWW0215ID10200210081700303UMI5204581253033605405500005802ID5914UDEV Midtrans6007JAKARTA61051234062070703A0163041D3A',
                    'actions' => [
                        [
                            'name' => 'generate-qr-code',
                            'method' => 'GET',
                            'url' => 'https://api.sandbox.midtrans.com/v2/qris/midtrans-tx-switch-01/qr-code',
                        ],
                    ],
                ], 201)
                ->push([
                    'status_code' => '201',
                    'status_message' => 'VA BCA transaction is created',
                    'transaction_id' => 'midtrans-tx-switch-02',
                    'order_id' => $invoice->invoice_number.'-A2',
                    'gross_amount' => '50000.00',
                    'payment_type' => 'bank_transfer',
                    'transaction_status' => 'pending',
                    'va_numbers' => [
                        ['bank' => 'bca', 'va_number' => '9988776655'],
                    ],
                ], 201),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        // First attempt: QRIS with key A
        $res1 = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'qris',
            'provider' => 'midtrans',
        ], ['Idempotency-Key' => 'idemp-switch-key-A']);
        $res1->assertOk();

        $attempt1 = PaymentAttempt::query()->where('invoice_id', $invoice->id)->where('attempt_number', 1)->firstOrFail();
        $this->assertSame(PaymentAttempt::StatusPending, $attempt1->status);

        // Second attempt: Switch to VA BCA with key B (fresh idempotency key from frontend)
        $res2 = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'va_bca',
            'bank' => 'bca',
            'provider' => 'midtrans',
        ], ['Idempotency-Key' => 'idemp-switch-key-B']);

        // Succeeded cleanly without HTTP 500 or duplicate key exception
        $res2->assertOk()
            ->assertJsonPath('data.payment_method', 'va_bca')
            ->assertJsonPath('data.payment_code', '9988776655');

        // Attempt 1 was cancelled cleanly
        $this->assertSame(PaymentAttempt::StatusCancelled, $attempt1->fresh()->status);

        // Attempt 2 was created as attempt_number 2
        $attempt2 = PaymentAttempt::query()->where('invoice_id', $invoice->id)->where('attempt_number', 2)->firstOrFail();
        $this->assertSame(PaymentAttempt::StatusPending, $attempt2->status);
        $this->assertSame('9988776655', $attempt2->metadata['payment_code']);
    }

    public function test_checkout_recovery_does_not_call_create_charge_when_transaction_already_exists(): void
    {
        [$invoice] = $this->setupInvoiceWithMidtrans();

        // Simulate that Midtrans already has this transaction created
        Http::fake([
            'https://api.sandbox.midtrans.com/v2/'.$invoice->invoice_number.'-A1/status' => Http::response([
                'status_code' => '200',
                'status_message' => 'Success, transaction is found',
                'transaction_id' => 'midtrans-tx-recovered-99',
                'order_id' => $invoice->invoice_number.'-A1',
                'gross_amount' => '50000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'pending',
            ], 200),
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '500',
                'status_message' => 'Should never be called!',
            ], 500),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        $res = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'qris',
            'provider' => 'midtrans',
        ], ['Idempotency-Key' => 'idemp-recovery-existing-01']);

        $res->assertOk()
            ->assertJsonPath('data.gateway_payment_id', 'midtrans-tx-recovered-99');

        // Charge was NOT called, only status query
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/charge'));
    }

    public function test_checkout_logs_and_marks_failure_when_credential_decryption_fails_without_leaking_secrets(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        // Corrupt encrypted server key so decryption fails
        $credential->update([
            'encrypted_server_key' => base64_encode('corrupted-data-that-fails-decryption'),
            'server_key_tag' => base64_encode(str_repeat('A', 16)),
        ]);

        Log::spy();

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        $res = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'qris',
            'provider' => 'midtrans',
        ], ['Idempotency-Key' => 'idemp-decrypt-fail-01']);

        $res->assertStatus(502);

        $outbox = CheckoutOutboxMessage::query()->latest('id')->firstOrFail();
        $this->assertSame(CheckoutOutboxMessage::StatusFailed, $outbox->status);
        $this->assertSame('credential_decryption_failed', $outbox->error_code);
        $this->assertNull($outbox->available_at);

        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context): bool {
            $isTarget = str_contains($message, 'credential_decryption_failed');
            if (! $isTarget) {
                return false;
            }

            // Verify safe sanitized logging
            $this->assertSame('credential_decryption_failed', $context['error_code']);
            $this->assertStringNotContainsString(self::TestServerKey, json_encode($context));
            $this->assertStringNotContainsString(self::TestServerKey, $message);
            $this->assertNotEmpty($context['location']);
            $this->assertNotEmpty($context['safe_trace']);

            return true;
        });
    }

    public function test_checkout_logs_and_marks_failure_when_gateway_charge_times_out_without_auto_retry(): void
    {
        [$invoice] = $this->setupInvoiceWithMidtrans();

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/'.$invoice->invoice_number.'-A1/status' => Http::response([
                'status_code' => '404',
                'status_message' => 'Transaction does not exist',
            ], 404),
            'https://api.sandbox.midtrans.com/v2/charge' => function () {
                throw new ConnectionException('cURL error 28: Operation timed out with SB-Mid-server-TESTKEY1234567890');
            },
        ]);

        Log::spy();

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        $res = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'qris',
            'provider' => 'midtrans',
        ], ['Idempotency-Key' => 'idemp-charge-timeout-01']);

        $res->assertStatus(502);

        $outbox = CheckoutOutboxMessage::query()->latest('id')->firstOrFail();
        // Uncertain gateway status: MUST NOT automatically retry!
        $this->assertSame(CheckoutOutboxMessage::StatusFailed, $outbox->status);
        $this->assertSame('gateway_connection_timeout', $outbox->error_code);
        $this->assertNull($outbox->available_at);

        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context): bool {
            $isTarget = str_contains($message, 'gateway_connection_timeout');
            if (! $isTarget) {
                return false;
            }

            $this->assertSame('gateway_connection_timeout', $context['error_code']);
            // Server key must be redacted in exception message and context
            $this->assertStringNotContainsString(self::TestServerKey, $message);
            $this->assertStringNotContainsString(self::TestServerKey, json_encode($context));
            $this->assertStringContainsString('[REDACTED_SERVER_KEY]', $message);

            return true;
        });
    }

    public function test_checkout_logs_and_marks_failure_when_gateway_returns_error_response(): void
    {
        [$invoice] = $this->setupInvoiceWithMidtrans();

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/'.$invoice->invoice_number.'-A1/status' => Http::response([
                'status_code' => '404',
                'status_message' => 'Transaction does not exist',
            ], 404),
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '400',
                'status_message' => 'Invalid gross amount or merchant inactive',
            ], 400),
        ]);

        Log::spy();

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        $res = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'qris',
            'provider' => 'midtrans',
        ], ['Idempotency-Key' => 'idemp-gateway-err-01']);

        $res->assertStatus(502);

        $outbox = CheckoutOutboxMessage::query()->latest('id')->firstOrFail();
        $this->assertSame(CheckoutOutboxMessage::StatusFailed, $outbox->status);
        $this->assertSame('gateway_response_invalid', $outbox->error_code);
        $this->assertNull($outbox->available_at);

        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'gateway_response_invalid')
                && $context['error_code'] === 'gateway_response_invalid';
        });
    }

    public function test_checkout_logs_and_marks_failure_when_payment_instructions_are_incomplete(): void
    {
        [$invoice] = $this->setupInvoiceWithMidtrans();

        // Response 201 from Midtrans for VA BCA but completely missing va_numbers
        Http::fake([
            'https://api.sandbox.midtrans.com/v2/'.$invoice->invoice_number.'-A1/status' => Http::response([
                'status_code' => '404',
            ], 404),
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'status_message' => 'VA created but empty',
                'transaction_id' => 'tx-empty-inst-01',
                'order_id' => $invoice->invoice_number.'-A1',
                'gross_amount' => '50000.00',
                'payment_type' => 'bank_transfer',
                'transaction_status' => 'pending',
                'va_numbers' => [],
            ], 201),
        ]);

        Log::spy();

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        $res = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'va_bca',
            'bank' => 'bca',
            'provider' => 'midtrans',
        ], ['Idempotency-Key' => 'idemp-empty-inst-01']);

        $res->assertStatus(502);

        $outbox = CheckoutOutboxMessage::query()->latest('id')->firstOrFail();
        $this->assertSame(CheckoutOutboxMessage::StatusFailed, $outbox->status);
        $this->assertSame('payment_instruction_invalid', $outbox->error_code);

        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'payment_instruction_invalid')
                && $context['error_code'] === 'payment_instruction_invalid'
                && str_contains($context['exception_message'], 'tidak lengkap');
        });
    }

    public function test_checkout_successfully_stores_36_character_uuid_gateway_payment_id(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        $uuid = '43e33f67-a2ef-4786-9a25-a131750699cf';
        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'status_message' => 'QRIS transaction is created',
                'transaction_id' => $uuid,
                'order_id' => $invoice->invoice_number.'-A1',
                'gross_amount' => '50000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'pending',
                'qr_string' => '00020101021226590014ID.LINKAJA.WWW0118936009110022201980020300051440014ID.GO-PAY.WWW0215ID10200210081700303UMI5204581253033605405500005802ID5914UDEV Midtrans6007JAKARTA61051234062070703A0163041D3A',
                'actions' => [
                    [
                        'name' => 'generate-qr-code',
                        'method' => 'GET',
                        'url' => "https://api.sandbox.midtrans.com/v2/qris/{$uuid}/qr-code",
                    ],
                ],
            ], 201),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        $response = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'qris',
        ], ['Idempotency-Key' => 'idemp-uuid-chk-001']);

        $response->assertOk()
            ->assertJsonPath('data.gateway_payment_id', $uuid);

        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame($uuid, $attempt->gateway_payment_id);
        $this->assertSame($uuid, $attempt->provider_reference);
        $this->assertSame(36, strlen((string) $attempt->gateway_payment_id));
        $this->assertSame("https://api.sandbox.midtrans.com/v2/qris/{$uuid}/qr-code", $attempt->checkout_url);
    }

    public function test_checkout_classifies_database_storage_failure_as_database_storage_failed_and_does_not_leak_bindings_or_pii(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        $uuid = '43e33f67-a2ef-4786-9a25-a131750699cf';
        $sensitiveQr = '00020101021226590014ID.LINKAJA.WWW0118936009110022201980020300051440014ID.GO-PAY.WWW0215ID10200210081700303UMI5204581253033605405500005802ID5914UDEV Midtrans6007JAKARTA61051234062070703A0163041D3A';
        $sensitiveEmail = 'sensitive_customer@udumbara.my.id';
        $sensitivePhone = '081234567890';
        $sensitiveCiphertext = 'eyJpdiI6InNlY3JldGl2MTIzNDU2IiwidmFsdWUiOiJzZWNyZXR2YWx1ZTEyMzQ1NiIsIm1hYyI6IjEyMzQ1NiJ9';

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'status_message' => 'QRIS transaction is created',
                'transaction_id' => $uuid,
                'order_id' => $invoice->invoice_number.'-A1',
                'gross_amount' => '50000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'pending',
                'qr_string' => $sensitiveQr,
                'actions' => [
                    [
                        'name' => 'generate-qr-code',
                        'method' => 'GET',
                        'url' => "https://api.sandbox.midtrans.com/v2/qris/{$uuid}/qr-code",
                    ],
                ],
            ], 201),
        ]);

        Log::spy();

        // Simulate QueryException during storeMidtransCheckout attempt update
        PaymentAttempt::updating(function (PaymentAttempt $model) use ($sensitiveQr, $sensitiveEmail, $sensitivePhone, $sensitiveCiphertext): void {
            if ($model->isDirty('gateway_payment_id')) {
                $pdoException = new \PDOException("SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'gateway_payment_id' at row 1");
                throw new QueryException(
                    'mysql',
                    'update `payment_attempts` set `gateway_payment_id` = ?, `checkout_url` = ?, `email` = ?, `phone` = ?, `qr` = ? where `id` = ?',
                    [
                        '43e33f67-a2ef-4786-9a25-a131750699cf',
                        $sensitiveCiphertext,
                        $sensitiveEmail,
                        $sensitivePhone,
                        $sensitiveQr,
                        $model->id,
                    ],
                    $pdoException
                );
            }
        });

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        try {
            $res = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
                'payment_method' => 'qris',
            ], ['Idempotency-Key' => 'idemp-db-error-001']);

            $res->assertStatus(502);

            $outbox = CheckoutOutboxMessage::query()->latest('id')->firstOrFail();
            $this->assertSame(CheckoutOutboxMessage::StatusFailed, $outbox->status);
            $this->assertSame('database_storage_failed', $outbox->error_code);

            Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context) use ($sensitiveQr, $sensitiveEmail, $sensitivePhone, $sensitiveCiphertext): bool {
                $isStorageFailed = str_contains($message, 'database_storage_failed')
                    && ($context['error_code'] ?? '') === 'database_storage_failed';

                $fullLogString = $message.' '.json_encode($context);
                $leaksQr = str_contains($fullLogString, $sensitiveQr);
                $leaksEmail = str_contains($fullLogString, $sensitiveEmail);
                $leaksPhone = str_contains($fullLogString, $sensitivePhone);
                $leaksCiphertext = str_contains($fullLogString, $sensitiveCiphertext);
                $leaksRawSql = str_contains($fullLogString, 'update `payment_attempts`');

                return $isStorageFailed && ! $leaksQr && ! $leaksEmail && ! $leaksPhone && ! $leaksCiphertext && ! $leaksRawSql;
            });
        } finally {
            PaymentAttempt::flushEventListeners();
            PaymentAttempt::bootIfNotBooted();
        }
    }

    public function test_checkout_service_sanitize_log_text_redacts_all_sensitive_patterns(): void
    {
        $checkoutService = app(CheckoutService::class);
        $method = new \ReflectionMethod($checkoutService, 'sanitizeLogText');

        $input = "Error: (Connection: mysql, SQL: select * from users where token = 'SB-Mid-server-abc123' and client = 'SB-Mid-client-xyz789' and auth = 'Authorization: Bearer mytoken123456789' and email = 'user@example.com' and phone = '081234567890' and qr = '00020101021226590014ID.LINKAJA.WWW' and cipher = 'eyJpdiI6Inh4eCIsInZhbHVlIjoiMTIzNCJ9' [bindings: [1, 2]])";

        $output = $method->invoke($checkoutService, $input);

        $this->assertStringNotContainsString('SB-Mid-server-abc123', $output);
        $this->assertStringNotContainsString('SB-Mid-client-xyz789', $output);
        $this->assertStringNotContainsString('mytoken123456789', $output);
        $this->assertStringNotContainsString('user@example.com', $output);
        $this->assertStringNotContainsString('081234567890', $output);
        $this->assertStringNotContainsString('00020101021226590014ID.LINKAJA.WWW', $output);
        $this->assertStringNotContainsString('eyJpdiI6Inh4eCIsInZhbHVlIjoiMTIzNCJ9', $output);
        $this->assertStringNotContainsString('[bindings:', $output);
    }

    public function test_query_sandbox_status_falls_back_to_direct_midtrans_when_microservice_returns_404(): void
    {
        config()->set('services.midtrans_gateway.gateway_service_url', 'http://127.0.0.1:8003');
        config()->set('services.midtrans_gateway.service_token', 'test-internal-token');

        $uuid = '13a03f8d-3450-4acc-b0d6-2382fab53613';
        $orderId = 'INV-20261101-VDWNVAOGGV-A4';

        Http::fake([
            'http://127.0.0.1:8003/api/v1/internal/merchants/larissama/sandbox-test/status/'.$orderId => Http::response([
                'status' => 'failed',
                'message' => 'Not found in microservice',
            ], 404),
            'https://api.sandbox.midtrans.com/v2/'.$orderId.'/status' => Http::response([
                'status_code' => '200',
                'status_message' => 'Midtrans transaction is expired',
                'transaction_id' => $uuid,
                'order_id' => $orderId,
                'gross_amount' => '50000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'expire',
            ], 200),
        ]);

        $client = app(MidtransGatewayProvisioningClient::class);
        $result = $client->querySandboxStatus('larissama', self::TestServerKey, $orderId, 'sandbox');

        $this->assertSame('success', $result['status']);
        $this->assertFalse($result['is_not_found']);
        $this->assertSame(200, $result['http_status']);
        $this->assertSame($uuid, $result['data']['transaction_id']);
        $this->assertSame('expire', $result['data']['transaction_status']);
    }

    public function test_checkout_precheck_handles_expired_order_without_duplicate_charge_or_settlement(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        $uuid = '13a03f8d-3450-4acc-b0d6-2382fab53613';
        $orderId = $invoice->invoice_number.'-A4';

        $attempt = PaymentAttempt::create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 4,
            'provider' => 'midtrans',
            'merchant_reference' => $orderId,
            'gateway_merchant_code' => self::MerchantCode,
            'gateway_environment' => 'sandbox',
            'amount' => $invoice->amount,
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusInitiated,
            'idempotency_key' => 'checkout:'.$invoice->id.':idemp-exp-004',
            'requested_at' => now(),
            'metadata' => [
                'payment_method' => 'qris',
            ],
        ]);

        $outbox = CheckoutOutboxMessage::create([
            'payment_attempt_id' => $attempt->id,
            'idempotency_key' => 'midtrans-checkout-attempt-'.$attempt->id,
            'status' => CheckoutOutboxMessage::StatusPending,
        ]);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/'.$orderId.'/status' => Http::response([
                'status_code' => '200',
                'status_message' => 'Midtrans transaction is expired',
                'transaction_id' => $uuid,
                'order_id' => $orderId,
                'gross_amount' => '50000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'expire',
            ], 200),
        ]);

        $checkoutService = app(CheckoutService::class);
        $method = new \ReflectionMethod($checkoutService, 'dispatch');
        $method->invoke($checkoutService, $outbox->id);

        $attempt->refresh();
        $outbox->refresh();

        // Status must be expired
        $this->assertSame(PaymentAttempt::StatusExpired, $attempt->status);
        $this->assertSame($uuid, $attempt->gateway_payment_id);
        $this->assertSame($uuid, $attempt->provider_reference);
        $this->assertSame('midtrans_expire', $attempt->failure_code);
        $this->assertNotNull($attempt->completed_at);

        // Outbox must be failed
        $this->assertSame(CheckoutOutboxMessage::StatusFailed, $outbox->status);
        $this->assertSame('transaction_expire', $outbox->error_code);

        // Invoice remains pending, NO settlement
        $this->assertSame(Invoice::StatusPending, $invoice->fresh()->status);
        $this->assertSame(0, PaymentAllocation::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame(0, SettlementEvent::query()->where('payment_attempt_id', $attempt->id)->count());

        // Ensure charge was NOT called
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/charge'));
    }

    public function test_retry_checkout_verifies_gateway_status_and_rejects_expired_attempt_without_duplicate_charge(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        $uuid = '13a03f8d-3450-4acc-b0d6-2382fab53613';
        $orderId = $invoice->invoice_number.'-A4';

        $attempt = PaymentAttempt::create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 4,
            'provider' => 'midtrans',
            'merchant_reference' => $orderId,
            'gateway_merchant_code' => self::MerchantCode,
            'gateway_environment' => 'sandbox',
            'amount' => $invoice->amount,
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusInitiated,
            'idempotency_key' => 'checkout:'.$invoice->id.':idemp-exp-retry-004',
            'requested_at' => now(),
            'metadata' => [
                'payment_method' => 'qris',
            ],
        ]);

        $outbox = CheckoutOutboxMessage::create([
            'payment_attempt_id' => $attempt->id,
            'idempotency_key' => 'midtrans-checkout-attempt-'.$attempt->id,
            'status' => CheckoutOutboxMessage::StatusFailed,
            'error_code' => 'network_timeout',
        ]);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/'.$orderId.'/status' => Http::response([
                'status_code' => '200',
                'status_message' => 'Midtrans transaction is expired',
                'transaction_id' => $uuid,
                'order_id' => $orderId,
                'gross_amount' => '50000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'expire',
            ], 200),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        $response = $this->postJson("/api/v1/admin/payment-attempts/{$attempt->id}/checkout/retry");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment_attempt']);

        $attempt->refresh();
        $this->assertSame(PaymentAttempt::StatusExpired, $attempt->status);
        $this->assertSame($uuid, $attempt->gateway_payment_id);
        $this->assertSame($uuid, $attempt->provider_reference);

        // Ensure NO charge was sent
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/charge'));
    }

    public function test_new_checkout_can_proceed_after_previous_attempt_expired(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        $uuidExpired = '13a03f8d-3450-4acc-b0d6-2382fab53613';
        $orderIdA4 = $invoice->invoice_number.'-A4';

        // Attempt A4 is expired
        PaymentAttempt::create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 4,
            'provider' => 'midtrans',
            'merchant_reference' => $orderIdA4,
            'gateway_payment_id' => $uuidExpired,
            'provider_reference' => $uuidExpired,
            'gateway_merchant_code' => self::MerchantCode,
            'gateway_environment' => 'sandbox',
            'amount' => $invoice->amount,
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusExpired,
            'idempotency_key' => 'checkout:'.$invoice->id.':idemp-exp-004',
            'requested_at' => now()->subHour(),
            'completed_at' => now()->subHour(),
            'metadata' => [
                'payment_method' => 'qris',
            ],
        ]);

        $newUuid = '24b14e9e-4561-5bdd-c1e7-3493gcb64724';
        $orderIdA5 = $invoice->invoice_number.'-A5';

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/'.$orderIdA5.'/status' => Http::response([
                'status_code' => '404',
                'status_message' => 'Transaction doesn\'t exist.',
            ], 404),
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'status_message' => 'QRIS transaction is created',
                'transaction_id' => $newUuid,
                'order_id' => $orderIdA5,
                'gross_amount' => '50000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'pending',
                'qr_string' => '00020101021226590014ID.LINKAJA.WWW',
                'actions' => [
                    [
                        'name' => 'generate-qr-code',
                        'method' => 'GET',
                        'url' => "https://api.sandbox.midtrans.com/v2/qris/{$newUuid}/qr-code",
                    ],
                ],
            ], 201),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        $res = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'qris',
        ], ['Idempotency-Key' => 'idemp-new-a5']);

        $res->assertOk()
            ->assertJsonPath('data.gateway_payment_id', $newUuid);

        $attempt5 = PaymentAttempt::query()->where('invoice_id', $invoice->id)->where('attempt_number', 5)->firstOrFail();
        $this->assertSame(PaymentAttempt::StatusPending, $attempt5->status);
        $this->assertSame($orderIdA5, $attempt5->merchant_reference);
        $this->assertSame($newUuid, $attempt5->gateway_payment_id);
    }

    public function test_reconciliation_synchronizes_expire_status_without_settlement_or_subscription_extension(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        $subscription = Subscription::factory()->create([
            'application_customer_id' => $invoice->application_customer_id,
            'subscription_plan_id' => $invoice->subscription_plan_id,
            'status' => 'active',
            'starts_at' => now()->subDays(10),
            'expires_at' => now()->addDays(20),
        ]);
        $invoice->update(['subscription_id' => $subscription->id]);
        $initialExpiresAt = $subscription->fresh()->expires_at->toISOString();

        $uuid = '13a03f8d-3450-4acc-b0d6-2382fab53613';
        $orderId = $invoice->invoice_number.'-A4';

        $attempt = PaymentAttempt::create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 4,
            'provider' => 'midtrans',
            'merchant_reference' => $orderId,
            'gateway_merchant_code' => self::MerchantCode,
            'gateway_environment' => 'sandbox',
            'amount' => $invoice->amount,
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusInitiated,
            'idempotency_key' => 'checkout:'.$invoice->id.':idemp-rec-exp',
            'requested_at' => now(),
            'metadata' => [
                'payment_method' => 'qris',
            ],
        ]);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/'.$orderId.'/status' => Http::response([
                'status_code' => '200',
                'status_message' => 'Midtrans transaction is expired',
                'transaction_id' => $uuid,
                'order_id' => $orderId,
                'gross_amount' => '50000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'expire',
            ], 200),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        $res = $this->postJson("/api/v1/admin/payment-attempts/{$attempt->id}/reconcile");

        $res->assertOk()
            ->assertJsonPath('data.status', 'expired');

        $attempt->refresh();
        $this->assertSame(PaymentAttempt::StatusExpired, $attempt->status);
        $this->assertSame($uuid, $attempt->gateway_payment_id);
        $this->assertSame($uuid, $attempt->provider_reference);

        // Subscription expiration date must NOT be extended!
        $this->assertSame($initialExpiresAt, $subscription->fresh()->expires_at->toISOString());

        // Invoice remains pending, NO settlement events
        $this->assertSame(Invoice::StatusPending, $invoice->fresh()->status);
        $this->assertSame(0, SettlementEvent::query()->where('payment_attempt_id', $attempt->id)->count());
        $this->assertSame(0, PaymentAllocation::query()->where('invoice_id', $invoice->id)->count());
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

    public function test_qr_image_proxy_returns_image_for_active_midtrans_attempt(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        $attempt = PaymentAttempt::create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'provider' => 'midtrans',
            'idempotency_key' => 'idemp-qr-image-test-001',
            'merchant_reference' => $invoice->invoice_number.'-A1',
            'provider_reference' => 'midtrans-tx-qris-001',
            'gateway_merchant_code' => self::MerchantCode,
            'gateway_environment' => 'sandbox',
            'gateway_payment_id' => 'midtrans-tx-qris-001',
            'amount' => '50000.00',
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusPending,
            'checkout_expires_at' => now()->addMinutes(15),
            'metadata' => [
                'payment_method' => 'qris',
                'payment_type' => 'qris',
                'transaction_id' => 'midtrans-tx-qris-001',
                'qr_url' => 'https://api.sandbox.midtrans.com/v2/qris/midtrans-tx-qris-001/qr-code',
                'qr_content' => '00020101021226590014...',
            ],
        ]);

        $fakePng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/qris/midtrans-tx-qris-001/qr-code' => Http::response($fakePng, 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        $response = $this->get("/api/v1/admin/payment-attempts/{$attempt->id}/qr-image");

        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame($fakePng, $response->getContent());
    }

    public function test_qr_image_proxy_returns_410_when_attempt_is_expired(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        $attempt = PaymentAttempt::create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'provider' => 'midtrans',
            'idempotency_key' => 'idemp-qr-image-test-expired',
            'merchant_reference' => $invoice->invoice_number.'-A1',
            'provider_reference' => 'midtrans-tx-qris-expired',
            'gateway_merchant_code' => self::MerchantCode,
            'gateway_environment' => 'sandbox',
            'gateway_payment_id' => 'midtrans-tx-qris-expired',
            'amount' => '50000.00',
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusExpired,
            'checkout_expires_at' => now()->subMinutes(5),
            'metadata' => [
                'payment_method' => 'qris',
                'transaction_id' => 'midtrans-tx-qris-expired',
                'qr_url' => 'https://api.sandbox.midtrans.com/v2/qris/midtrans-tx-qris-expired/qr-code',
            ],
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        $response = $this->getJson("/api/v1/admin/payment-attempts/{$attempt->id}/qr-image");

        $response->assertStatus(410)
            ->assertJsonPath('error', 'qris_expired');
    }

    public function test_checkout_resource_separates_qr_url_qr_string_and_transaction_id(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'status_message' => 'QRIS transaction is created',
                'transaction_id' => 'midtrans-tx-sep-001',
                'order_id' => $invoice->invoice_number.'-A1',
                'gross_amount' => '50000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'pending',
                'expiry_time' => now()->addHour()->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s'),
                'qr_string' => '00020101021226590014ID.LINKAJA.WWW0118...',
                'actions' => [
                    [
                        'name' => 'generate-qr-code',
                        'method' => 'GET',
                        'url' => 'https://api.sandbox.midtrans.com/v2/qris/midtrans-tx-sep-001/qr-code',
                    ],
                ],
            ], 201),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        $response = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'qris',
        ], ['Idempotency-Key' => 'idemp-midtrans-chk-sep-001']);

        $response->assertOk()
            ->assertJsonPath('data.transaction_id', 'midtrans-tx-sep-001')
            ->assertJsonPath('data.qr_url', 'https://api.sandbox.midtrans.com/v2/qris/midtrans-tx-sep-001/qr-code')
            ->assertJsonPath('data.qr_string', '00020101021226590014ID.LINKAJA.WWW0118...')
            ->assertJsonPath('data.is_expired', false);

        $this->assertStringContainsString('qr-image', $response->json('data.qr_image_url'));
    }

    public function test_midtrans_qris_checkout_converts_wib_expiry_time_to_utc_in_database(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        // Midtrans returns expiry in WIB (Asia/Jakarta): 2026-11-02 22:02:43 WIB = 2026-11-02 15:02:43 UTC
        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'status_message' => 'QRIS transaction is created',
                'transaction_id' => 'midtrans-tx-a5-tz',
                'order_id' => $invoice->invoice_number.'-A1',
                'gross_amount' => '50000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'pending',
                'expiry_time' => '2026-11-02 22:02:43',
                'qr_string' => '00020101021226590014ID.LINKAJA.WWW0118...',
                'actions' => [
                    [
                        'name' => 'generate-qr-code',
                        'method' => 'GET',
                        'url' => 'https://api.sandbox.midtrans.com/v2/qris/midtrans-tx-a5-tz/qr-code',
                    ],
                ],
            ], 201),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        $response = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'qris',
        ], ['Idempotency-Key' => 'idemp-midtrans-tz-001']);

        $response->assertOk();

        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->firstOrFail();
        // Assert exact UTC conversion (22:02:43 WIB -> 15:02:43 UTC)
        $this->assertSame('2026-11-02 15:02:43', $attempt->checkout_expires_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-02T15:02:43+00:00', $attempt->checkout_expires_at->toIso8601String());
    }

    public function test_midtrans_expire_status_syncs_idempotently_updates_expiry_and_prevents_settlement(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        $attempt = PaymentAttempt::create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'provider' => 'midtrans',
            'idempotency_key' => 'idemp-expire-sync-test',
            'merchant_reference' => $invoice->invoice_number.'-A1',
            'provider_reference' => null,
            'gateway_merchant_code' => self::MerchantCode,
            'gateway_environment' => 'sandbox',
            'gateway_payment_id' => null,
            'amount' => '50000.00',
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusPending,
            'checkout_expires_at' => now()->addHours(24), // Initially erroneously set to 24 hours
            'metadata' => ['payment_method' => 'qris'],
        ]);

        $outbox = CheckoutOutboxMessage::create([
            'payment_attempt_id' => $attempt->id,
            'idempotency_key' => 'midtrans-checkout-attempt-'.$attempt->id,
            'status' => CheckoutOutboxMessage::StatusProcessing,
        ]);

        // Midtrans status query returns expired with actual expiry time (Case A5)
        Http::fake([
            'https://api.sandbox.midtrans.com/v2/'.$invoice->invoice_number.'-A1/status' => Http::response([
                'status_code' => '200',
                'status_message' => 'Midtrans transaction is expired',
                'transaction_id' => '13a03f8d-3450-4acc-b0d6-2382fab53613',
                'order_id' => $invoice->invoice_number.'-A1',
                'gross_amount' => '50000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'expire',
                'expiry_time' => '2026-11-02 22:02:43',
            ], 200),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        // First reconcile call
        $response1 = $this->postJson("/api/v1/admin/payment-attempts/{$attempt->id}/reconcile");
        $response1->assertOk()
            ->assertJsonPath('data.status', 'expired')
            ->assertJsonPath('data.is_expired', true);

        $attempt->refresh();
        $this->assertSame(PaymentAttempt::StatusExpired, $attempt->status);
        $this->assertSame('13a03f8d-3450-4acc-b0d6-2382fab53613', $attempt->gateway_payment_id);
        $this->assertSame('2026-11-02 15:02:43', $attempt->checkout_expires_at->format('Y-m-d H:i:s'));
        $this->assertSame(Invoice::StatusPending, $invoice->fresh()->status);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('subscription_period_events', 0);

        // Second reconcile call (idempotent)
        $response2 = $this->postJson("/api/v1/admin/payment-attempts/{$attempt->id}/reconcile");
        $response2->assertOk()
            ->assertJsonPath('data.status', 'expired');

        $this->assertSame(PaymentAttempt::StatusExpired, $attempt->fresh()->status);
        $this->assertSame(Invoice::StatusPending, $invoice->fresh()->status);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_expired_payment_attempt_cannot_be_simulated_and_does_not_create_new_checkout(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        $attempt = PaymentAttempt::create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'provider' => 'midtrans',
            'idempotency_key' => 'idemp-sim-block-test',
            'merchant_reference' => $invoice->invoice_number.'-A1',
            'provider_reference' => 'tx-expired-sim',
            'gateway_merchant_code' => self::MerchantCode,
            'gateway_environment' => 'sandbox',
            'gateway_payment_id' => 'tx-expired-sim',
            'amount' => '50000.00',
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusExpired,
            'checkout_expires_at' => now()->subMinutes(10),
            'metadata' => ['payment_method' => 'qris'],
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        $response = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/sandbox-simulate-payment", [
            'action' => 'failed',
        ]);

        $response->assertStatus(422);

        // Assert no new attempt was created
        $this->assertSame(1, PaymentAttempt::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame(Invoice::StatusPending, $invoice->fresh()->status);
    }

    public function test_invoice_checkout_with_indomaret_cstore_creates_pending_attempt_with_payment_code(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        MidtransPaymentMethodFee::query()->create([
            'merchant_code' => 'udev-central',
            'payment_method' => 'indomaret',
            'payment_method_name' => 'Indomaret',
            'fee_type' => 'fixed',
            'fee_rate' => 0.0,
            'fixed_amount' => 5000.00,
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'status_message' => 'Indomaret transaction is created',
                'transaction_id' => 'midtrans-tx-cstore-001',
                'order_id' => $invoice->invoice_number.'-A1',
                'gross_amount' => '55000.00',
                'payment_type' => 'cstore',
                'transaction_status' => 'pending',
                'payment_code' => '1029384756',
                'store' => 'indomaret',
                'expiry_time' => '2026-11-09 23:59:59',
            ], 201),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        $response = $this->postJson("/api/v1/admin/invoices/{$invoice->id}/checkout", [
            'payment_method' => 'indomaret',
        ], ['Idempotency-Key' => 'idemp-midtrans-indomaret-001']);

        $response->assertOk()
            ->assertJsonPath('data.invoice_id', $invoice->id)
            ->assertJsonPath('data.gateway_payment_id', 'midtrans-tx-cstore-001')
            ->assertJsonPath('data.payment_method', 'indomaret')
            ->assertJsonPath('data.payment_code', '1029384756')
            ->assertJsonPath('data.checkout_status', 'ready')
            ->assertJsonPath('data.status', PaymentAttempt::StatusPending)
            ->assertJsonPath('data.payment_instruction.store', 'indomaret')
            ->assertJsonPath('data.payment_instruction.payment_code', '1029384756');

        $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame('midtrans', $attempt->provider);
        $this->assertSame(self::MerchantCode, $attempt->gateway_merchant_code);
        $this->assertSame('1029384756', $attempt->metadata['payment_code']);
        $this->assertSame('indomaret', $attempt->metadata['store']);
        $this->assertSame('cstore', $attempt->metadata['payment_instruction']['type']);
        $this->assertNotEmpty($attempt->metadata['payment_instruction']['instructions']);

        // Assert fee separation: subtotal = 50,000, fee = 5,000, total = 55,000
        $this->assertEquals(55000.00, (float) $attempt->amount);
        $this->assertEquals(5000.00, (float) $attempt->admin_fee);
        $this->assertSame(Invoice::StatusPending, $invoice->fresh()->status);
    }

    public function test_indomaret_webhook_settlement_marks_invoice_paid_and_is_idempotent(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        $attempt = PaymentAttempt::query()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'provider' => 'midtrans',
            'merchant_reference' => $invoice->invoice_number.'-A1',
            'gateway_merchant_code' => self::MerchantCode,
            'gateway_environment' => 'sandbox',
            'amount' => '55000.00',
            'admin_fee' => '5000.00',
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusPending,
            'gateway_payment_id' => 'midtrans-cstore-tx-101',
            'idempotency_key' => 'checkout:'.$invoice->id.':cstore',
            'requested_at' => now(),
            'metadata' => [
                'order_id' => $invoice->invoice_number.'-A1',
                'payment_method' => 'indomaret',
                'payment_code' => '1029384756',
                'store' => 'indomaret',
                'fee_breakdown' => [
                    'fee_amount' => '5000.00',
                    'subtotal_amount' => '50000.00',
                ],
            ],
        ]);

        $orderId = $attempt->merchant_reference;
        $statusCode = '200';
        $grossAmount = '55000.00';
        $signature = hash('sha512', $orderId.$statusCode.$grossAmount.self::TestServerKey);

        $payload = [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'transaction_id' => 'midtrans-cstore-tx-101',
            'payment_type' => 'cstore',
            'settlement_time' => now()->format('Y-m-d H:i:s'),
        ];

        // 1. Initial Webhook Settlement
        $response = $this->postJson('/api/v1/payment-gateways/midtrans/webhook', $payload);
        $response->assertOk()
            ->assertJsonPath('status', 'OK')
            ->assertJsonPath('transaction_status', 'settlement');

        $this->assertSame(PaymentAttempt::StatusSucceeded, $attempt->fresh()->status);
        $this->assertSame(Invoice::StatusPaid, $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->paid_at);
        $this->assertSame(1, SettlementEvent::query()->where('payment_attempt_id', $attempt->id)->count());
        $this->assertSame(1, PaymentAllocation::query()->where('invoice_id', $invoice->id)->count());

        // 2. Duplicate Webhook (Idempotency check)
        $dupResponse = $this->postJson('/api/v1/payment-gateways/midtrans/webhook', $payload);
        $dupResponse->assertOk()
            ->assertJsonPath('status', 'OK');

        $this->assertSame(PaymentAttempt::StatusSucceeded, $attempt->fresh()->status);
        $this->assertSame(Invoice::StatusPaid, $invoice->fresh()->status);
        $this->assertSame(1, SettlementEvent::query()->where('payment_attempt_id', $attempt->id)->count());
        $this->assertSame(1, PaymentAllocation::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_indomaret_webhook_expire_and_deny_handled_safely(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        $attempt = PaymentAttempt::query()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'provider' => 'midtrans',
            'merchant_reference' => $invoice->invoice_number.'-A1',
            'gateway_merchant_code' => self::MerchantCode,
            'gateway_environment' => 'sandbox',
            'amount' => '55000.00',
            'admin_fee' => '5000.00',
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusPending,
            'gateway_payment_id' => 'midtrans-cstore-tx-102',
            'idempotency_key' => 'checkout:'.$invoice->id.':cstore-expire',
            'requested_at' => now(),
            'metadata' => [
                'order_id' => $invoice->invoice_number.'-A1',
                'payment_method' => 'indomaret',
                'payment_code' => '1029384756',
            ],
        ]);

        $orderId = $attempt->merchant_reference;
        $statusCode = '200';
        $grossAmount = '55000.00';
        $signature = hash('sha512', $orderId.$statusCode.$grossAmount.self::TestServerKey);

        // Test expire
        $expirePayload = [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signature,
            'transaction_status' => 'expire',
            'status_message' => 'Indomaret transaction expired',
            'transaction_id' => 'midtrans-cstore-tx-102',
            'payment_type' => 'cstore',
        ];

        $response = $this->postJson('/api/v1/payment-gateways/midtrans/webhook', $expirePayload);
        $response->assertOk()
            ->assertJsonPath('status', 'OK')
            ->assertJsonPath('transaction_status', 'expire');

        $this->assertSame(PaymentAttempt::StatusExpired, $attempt->fresh()->status);
        $this->assertSame(Invoice::StatusPending, $invoice->fresh()->status);

        // Test deny on new attempt
        $attemptDeny = PaymentAttempt::query()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 2,
            'provider' => 'midtrans',
            'merchant_reference' => $invoice->invoice_number.'-A2',
            'gateway_merchant_code' => self::MerchantCode,
            'gateway_environment' => 'sandbox',
            'amount' => '55000.00',
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusPending,
            'gateway_payment_id' => 'midtrans-cstore-tx-103',
            'idempotency_key' => 'checkout:'.$invoice->id.':cstore-deny',
            'requested_at' => now(),
            'metadata' => ['payment_method' => 'indomaret'],
        ]);

        $orderIdDeny = $attemptDeny->merchant_reference;
        $sigDeny = hash('sha512', $orderIdDeny.$statusCode.$grossAmount.self::TestServerKey);

        $denyPayload = [
            'order_id' => $orderIdDeny,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $sigDeny,
            'transaction_status' => 'deny',
            'status_message' => 'Indomaret transaction denied',
            'transaction_id' => 'midtrans-cstore-tx-103',
            'payment_type' => 'cstore',
        ];

        $responseDeny = $this->postJson('/api/v1/payment-gateways/midtrans/webhook', $denyPayload);
        $responseDeny->assertOk()
            ->assertJsonPath('status', 'OK')
            ->assertJsonPath('transaction_status', 'deny');

        $this->assertSame(PaymentAttempt::StatusFailed, $attemptDeny->fresh()->status);
        $this->assertSame(Invoice::StatusPending, $invoice->fresh()->status);
    }

    public function test_indomaret_webhook_payment_reversal_handled_safely(): void
    {
        [$invoice, $credential] = $this->setupInvoiceWithMidtrans();

        $attempt = PaymentAttempt::query()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'provider' => 'midtrans',
            'merchant_reference' => $invoice->invoice_number.'-A1',
            'gateway_merchant_code' => self::MerchantCode,
            'gateway_environment' => 'sandbox',
            'amount' => '55000.00',
            'admin_fee' => '5000.00',
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusSucceeded,
            'gateway_payment_id' => 'midtrans-cstore-tx-104',
            'idempotency_key' => 'checkout:'.$invoice->id.':cstore-reversal',
            'requested_at' => now(),
            'paid_at' => now(),
            'metadata' => [
                'order_id' => $invoice->invoice_number.'-A1',
                'payment_method' => 'indomaret',
                'payment_code' => '1029384756',
            ],
        ]);
        $invoice->update(['status' => Invoice::StatusPaid, 'paid_at' => now()]);

        $orderId = $attempt->merchant_reference;
        $statusCode = '200';
        $grossAmount = '55000.00';
        $signature = hash('sha512', $orderId.$statusCode.$grossAmount.self::TestServerKey);

        $refundPayload = [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signature,
            'transaction_status' => 'refund',
            'status_message' => 'Indomaret payment refunded',
            'transaction_id' => 'midtrans-cstore-tx-104',
            'payment_type' => 'cstore',
        ];

        $response = $this->postJson('/api/v1/payment-gateways/midtrans/webhook', $refundPayload);
        $response->assertOk()
            ->assertJsonPath('status', 'OK')
            ->assertJsonPath('transaction_status', 'refund');

        $attempt->refresh();
        $this->assertArrayHasKey('reversal', $attempt->metadata);
        $this->assertSame('refund', $attempt->metadata['reversal']['transaction_status']);
        $this->assertSame('midtrans_refund', $attempt->failure_code);
    }
}
