<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\ApplicationPaymentGateway;
use App\Models\MidtransMerchantCredential;
use App\Models\SubscriptionPlan;
use App\Services\MidtransSecretStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExternalApplicationMidtransIntegrationE2ETest extends TestCase
{
    use RefreshDatabase;

    private MidtransSecretStore $secretStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secretStore = app(MidtransSecretStore::class);
    }

    public function test_external_application_can_preview_create_invoice_checkout_midtrans_and_activate_subscription_via_webhook(): void
    {
        CarbonImmutable::setTestNow('2026-11-01 10:00:00');

        [$appLarissama, $tokenLarissama] = $this->createApplicationWithToken('Larissama');
        $this->createMidtransMerchant('M-LARISSAMA', 'server-key-larissama-123', $appLarissama);

        $customer = ApplicationCustomer::factory()->create([
            'application_id' => $appLarissama->id,
            'external_customer_id' => 'LRS-USER-001',
            'name' => 'Budi Larissama',
            'email' => 'budi@larissama.test',
        ]);

        $plan = SubscriptionPlan::factory()->create([
            'application_id' => $appLarissama->id,
            'code' => 'PRO-30',
            'name' => 'Paket Pro Bulanan',
            'price' => '150000.00',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        // 1. Preview Invoice
        $this->withToken($tokenLarissama)
            ->postJson("/api/v1/integrations/customers/{$customer->external_customer_id}/invoice-preview", [
                'subscription_plan_code' => $plan->code,
                'period_count' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('data.customer_external_id', 'LRS-USER-001')
            ->assertJsonPath('data.plan.duration_days', 60)
            ->assertJsonPath('data.plan.period_count', 2)
            ->assertJsonPath('data.pricing.normal_price', '300000.00')
            ->assertJsonPath('data.pricing.final_price', '300000.00')
            ->assertJsonPath('data.available_gateways.0.provider', 'midtrans');

        // 2. Create Invoice
        $createRes = $this->withToken($tokenLarissama)
            ->postJson("/api/v1/integrations/customers/{$customer->external_customer_id}/invoices", [
                'subscription_plan_code' => $plan->code,
                'period_count' => 2,
                'due_at' => now()->addDays(3)->toISOString(),
            ], ['Idempotency-Key' => 'lrs-inv-create-001'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.plan.duration_days', 60)
            ->assertJsonPath('data.pricing.final_price', '300000.00');

        $invoiceNumber = $createRes->json('data.invoice_number');
        $this->assertNotEmpty($invoiceNumber);

        // Fake Midtrans Core API Charge for BCA VA
        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'status_message' => 'Success, Bank Transfer transaction is created',
                'transaction_id' => 'midtrans-trx-lrs-99',
                'order_id' => $invoiceNumber.'-A1',
                'gross_amount' => '300000.00',
                'payment_type' => 'bank_transfer',
                'transaction_time' => '2026-11-01 10:01:00',
                'transaction_status' => 'pending',
                'va_numbers' => [
                    [
                        'bank' => 'bca',
                        'va_number' => '100889977112233',
                    ],
                ],
                'fraud_status' => 'accept',
            ], 201),
        ]);

        // 3. Checkout Midtrans BCA VA
        $this->withToken($tokenLarissama)
            ->postJson("/api/v1/integrations/invoices/{$invoiceNumber}/checkout", [
                'provider' => 'midtrans',
                'bank' => 'bca',
                'payment_method' => 'va_bca',
                'idempotency_key' => 'lrs-checkout-001',
            ])
            ->assertOk()
            ->assertJsonPath('data.payment.provider', 'midtrans')
            ->assertJsonPath('data.payment.payment_method', 'va_bca')
            ->assertJsonPath('data.payment.payment_code', '100889977112233')
            ->assertJsonPath('data.payment.checkout_status', 'ready')
            ->assertJsonPath('data.payment.payment_instruction.type', 'virtual_account')
            ->assertJsonPath('data.payment.payment_instruction.bank', 'BCA');

        // 4. Check Status Invoice via Integration API
        $this->withToken($tokenLarissama)
            ->getJson("/api/v1/integrations/invoices/{$invoiceNumber}")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.payment.status', 'pending')
            ->assertJsonPath('data.payment.payment_code', '100889977112233');

        // 5. Midtrans Webhook Settlement
        $orderId = $invoiceNumber.'-A1';
        $grossAmount = '300000.00';
        $serverKey = 'server-key-larissama-123';
        $signature = hash('sha512', $orderId.'200'.$grossAmount.$serverKey);

        $this->postJson('/api/v1/payment-gateways/midtrans/webhook', [
            'order_id' => $orderId,
            'status_code' => '200',
            'gross_amount' => $grossAmount,
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
            'transaction_id' => 'midtrans-trx-lrs-99',
            'settlement_time' => '2026-11-01 10:05:00',
            'payment_type' => 'bank_transfer',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Webhook berhasil diverifikasi dan invoice telah lunas.');

        // Verify Invoice is PAID and Subscription is ACTIVE
        $this->assertDatabaseHas('invoices', [
            'invoice_number' => $invoiceNumber,
            'status' => 'paid',
        ]);

        $this->assertDatabaseHas('subscriptions', [
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
        ]);

        // 6. Check Customer Subscription via Integration API
        $this->withToken($tokenLarissama)
            ->getJson("/api/v1/integrations/customers/{$customer->external_customer_id}/subscription")
            ->assertOk()
            ->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.subscription.plan_code', 'PRO-30');

        // 7. Check Customer Transactions & Payment History via Integration API
        $this->withToken($tokenLarissama)
            ->getJson("/api/v1/integrations/customers/{$customer->external_customer_id}/transactions")
            ->assertOk()
            ->assertJsonPath('data.0.invoice_number', $invoiceNumber)
            ->assertJsonPath('data.0.status', 'paid')
            ->assertJsonPath('data.0.payment.provider', 'midtrans')
            ->assertJsonPath('data.0.payment_history.0.attempt_number', 1)
            ->assertJsonPath('data.0.payment_history.0.provider', 'midtrans')
            ->assertJsonPath('data.0.payment_history.0.status', 'succeeded');
    }

    public function test_webhook_duplicate_settlement_is_idempotent_and_does_not_extend_subscription_twice(): void
    {
        CarbonImmutable::setTestNow('2026-11-01 10:00:00');

        [$appLarissama, $tokenLarissama] = $this->createApplicationWithToken('Larissama');
        $this->createMidtransMerchant('M-LARISSAMA', 'server-key-larissama-123', $appLarissama);

        $customer = ApplicationCustomer::factory()->create([
            'application_id' => $appLarissama->id,
            'external_customer_id' => 'LRS-IDEMP-01',
        ]);
        $plan = SubscriptionPlan::factory()->create([
            'application_id' => $appLarissama->id,
            'price' => '100000.00',
            'duration_days' => 30,
        ]);

        $createRes = $this->withToken($tokenLarissama)
            ->postJson("/api/v1/integrations/customers/{$customer->external_customer_id}/invoices", [
                'subscription_plan_code' => $plan->code,
                'period_count' => 1,
                'due_at' => now()->addDays(2)->toISOString(),
            ], ['Idempotency-Key' => 'idemp-inv-1'])
            ->assertCreated();
        $invoiceNumber = $createRes->json('data.invoice_number');

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'order_id' => $invoiceNumber.'-A1',
                'gross_amount' => '100000.00',
                'payment_type' => 'qris',
                'qr_string' => '00020101021226580016ID.CO.MIDTRANS01',
                'transaction_status' => 'pending',
                'transaction_id' => 'midtrans-trx-idemp',
            ], 201),
        ]);

        $this->withToken($tokenLarissama)
            ->postJson("/api/v1/integrations/invoices/{$invoiceNumber}/checkout", [
                'provider' => 'midtrans',
                'payment_method' => 'qris',
                'idempotency_key' => 'idemp-chk-1',
            ])
            ->assertOk();

        $orderId = $invoiceNumber.'-A1';
        $grossAmount = '100000.00';
        $serverKey = 'server-key-larissama-123';
        $signature = hash('sha512', $orderId.'200'.$grossAmount.$serverKey);

        $payload = [
            'order_id' => $orderId,
            'status_code' => '200',
            'gross_amount' => $grossAmount,
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
            'transaction_id' => 'midtrans-trx-idemp',
            'settlement_time' => '2026-11-01 10:02:00',
            'payment_type' => 'qris',
        ];

        // First webhook
        $this->postJson('/api/v1/payment-gateways/midtrans/webhook', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'OK')
            ->assertJsonPath('message', 'Webhook berhasil diverifikasi dan invoice telah lunas.');

        $subscription = \App\Models\Subscription::query()->where('application_customer_id', $customer->id)->sole();
        $initialExpiresAt = $subscription->expires_at->timestamp;

        // Second (duplicate) webhook
        $this->postJson('/api/v1/payment-gateways/midtrans/webhook', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'OK');

        $subscription->refresh();
        $this->assertSame($initialExpiresAt, $subscription->expires_at->timestamp);
        $invoice = \App\Models\Invoice::query()->where('invoice_number', $invoiceNumber)->sole();
        $this->assertSame(1, \App\Models\PaymentAllocation::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_checkout_gateway_failure_allows_external_app_to_retry_safely(): void
    {
        CarbonImmutable::setTestNow('2026-11-01 10:00:00');

        [$appLarissama, $tokenLarissama] = $this->createApplicationWithToken('Larissama');
        $this->createMidtransMerchant('M-LARISSAMA', 'server-key-larissama-123', $appLarissama);

        $customer = ApplicationCustomer::factory()->create([
            'application_id' => $appLarissama->id,
            'external_customer_id' => 'LRS-RETRY-01',
        ]);
        $plan = SubscriptionPlan::factory()->create([
            'application_id' => $appLarissama->id,
            'price' => '100000.00',
        ]);

        $createRes = $this->withToken($tokenLarissama)
            ->postJson("/api/v1/integrations/customers/{$customer->external_customer_id}/invoices", [
                'subscription_plan_code' => $plan->code,
                'period_count' => 1,
                'due_at' => now()->addDays(2)->toISOString(),
            ], ['Idempotency-Key' => 'retry-inv-1'])
            ->assertCreated();
        $invoiceNumber = $createRes->json('data.invoice_number');

        // Sequence: 1st fails with 502 Bad Gateway, 2nd succeeds with 201
        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::sequence()
                ->push(['status_code' => '502', 'status_message' => 'Bad Gateway'], 502)
                ->push([
                    'status_code' => '201',
                    'order_id' => $invoiceNumber.'-A1',
                    'gross_amount' => '100000.00',
                    'payment_type' => 'gopay',
                    'actions' => [
                        [
                            'name' => 'deeplink-redirect',
                            'method' => 'GET',
                            'url' => 'https://simulator.sandbox.midtrans.com/gopay/partner/app/payment-pin?id=retry-1',
                        ],
                    ],
                    'transaction_status' => 'pending',
                    'transaction_id' => 'midtrans-trx-retry',
                ], 201),
        ]);

        // Attempt 1 fails
        $this->withToken($tokenLarissama)
            ->postJson("/api/v1/integrations/invoices/{$invoiceNumber}/checkout", [
                'provider' => 'midtrans',
                'payment_method' => 'gopay',
                'idempotency_key' => 'retry-chk-1',
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.payment.checkout_status', 'failed');

        // Retry via Admin / Retry endpoint or CheckoutService
        $paymentAttempt = \App\Models\PaymentAttempt::query()->latest('id')->first();
        $this->assertNotNull($paymentAttempt);

        $retryAttempt = app(\App\Services\CheckoutService::class)->retryCheckout($paymentAttempt);
        $this->assertNotNull($retryAttempt->checkout_url);
        $this->assertSame('pending', $retryAttempt->status);
    }

    public function test_merchant_and_data_isolation_between_external_applications(): void
    {
        CarbonImmutable::setTestNow('2026-11-01 10:00:00');

        [$appLarissama, $tokenLarissama] = $this->createApplicationWithToken('Larissama');
        $this->createMidtransMerchant('M-LARISSAMA', 'key-lrs', $appLarissama);

        [$appLaundry, $tokenLaundry] = $this->createApplicationWithToken('Laundry');
        $this->createMidtransMerchant('M-LAUNDRY', 'key-lnd', $appLaundry);

        $customerLarissama = ApplicationCustomer::factory()->create([
            'application_id' => $appLarissama->id,
            'external_customer_id' => 'CUST-LRS-ISOL',
        ]);
        $planLarissama = SubscriptionPlan::factory()->create([
            'application_id' => $appLarissama->id,
            'price' => '100000.00',
        ]);

        $createRes = $this->withToken($tokenLarissama)
            ->postJson("/api/v1/integrations/customers/{$customerLarissama->external_customer_id}/invoices", [
                'subscription_plan_code' => $planLarissama->code,
                'period_count' => 1,
                'due_at' => now()->addDays(2)->toISOString(),
            ], ['Idempotency-Key' => 'lrs-inv-isol-1'])
            ->assertCreated();
        $invoiceNumber = $createRes->json('data.invoice_number');

        // Laundry token CANNOT access Larissama invoice
        $this->withToken($tokenLaundry)
            ->getJson("/api/v1/integrations/invoices/{$invoiceNumber}")
            ->assertNotFound();

        // Laundry token CANNOT checkout Larissama invoice
        $this->withToken($tokenLaundry)
            ->postJson("/api/v1/integrations/invoices/{$invoiceNumber}/checkout", [
                'provider' => 'midtrans',
                'idempotency_key' => 'lnd-chk-attempt',
            ])
            ->assertNotFound();

        // Laundry token CANNOT see Larissama customer transactions
        $this->withToken($tokenLaundry)
            ->getJson("/api/v1/integrations/customers/{$customerLarissama->external_customer_id}/transactions")
            ->assertNotFound();

        // Midtrans Webhook with Signature using Laundry's server key CANNOT settle Larissama's order
        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'order_id' => $invoiceNumber.'-A1',
                'gross_amount' => '100000.00',
                'payment_type' => 'qris',
                'qr_string' => '00020101',
                'transaction_status' => 'pending',
                'transaction_id' => 'trx-isol',
            ], 201),
        ]);

        $this->withToken($tokenLarissama)
            ->postJson("/api/v1/integrations/invoices/{$invoiceNumber}/checkout", [
                'provider' => 'midtrans',
                'payment_method' => 'qris',
                'idempotency_key' => 'chk-isol-1',
            ]);

        $orderId = $invoiceNumber.'-A1';
        $grossAmount = '100000.00';
        $tamperedSignature = hash('sha512', $orderId.'200'.$grossAmount.'key-lnd'); // using Laundry key for Larissama order

        $this->postJson('/api/v1/payment-gateways/midtrans/webhook', [
            'order_id' => $orderId,
            'status_code' => '200',
            'gross_amount' => $grossAmount,
            'signature_key' => $tamperedSignature,
            'transaction_status' => 'settlement',
            'transaction_id' => 'trx-isol',
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Signature Midtrans tidak valid.');
    }

    /**
     * @return array{0: Application, 1: string}
     */
    private function createApplicationWithToken(string $appName): array
    {
        $application = Application::factory()->create(['name' => $appName]);
        $plainToken = 'tok-'.strtolower($appName).'-'.bin2hex(random_bytes(8));

        $application->integrationTokens()->create([
            'name' => "Global Token {$appName}",
            'token_hash' => hash('sha256', $plainToken),
            'token_prefix' => substr($plainToken, 0, 8),
            'token_kind' => 'global',
            'status' => 'active',
            'scopes' => ['*'],
        ]);

        return [$application, $plainToken];
    }

    private function createMidtransMerchant(string $merchantCode, string $serverKey, Application $application): void
    {
        $enc = $this->secretStore->encryptSecret($serverKey, $merchantCode, 'server_key');

        MidtransMerchantCredential::query()->create([
            'merchant_code' => $merchantCode,
            'merchant_name' => "Merchant {$merchantCode}",
            'environment' => 'sandbox',
            'is_active' => true,
            'encrypted_server_key' => $enc['ciphertext'],
            'server_key_nonce' => $enc['nonce'],
            'server_key_tag' => $enc['tag'],
            'server_key_version' => $enc['key_version'],
        ]);

        ApplicationPaymentGateway::query()->create([
            'application_id' => $application->id,
            'gateway_merchant_code' => $merchantCode,
            'provider' => 'midtrans',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);
    }
}
