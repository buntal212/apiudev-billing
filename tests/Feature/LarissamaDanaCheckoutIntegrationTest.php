<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\ApplicationIntegrationToken;
use App\Models\ApplicationPaymentGateway;
use App\Models\DanaPaymentFeeSetting;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LarissamaDanaCheckoutIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.dana_gateway', [
            'environment' => 'sandbox',
            'base_urls' => ['sandbox' => 'https://gateway.test', 'production' => 'https://gateway-prod.test'],
            'timeout' => 8,
            'connect_timeout' => 3,
            'merchant_api_keys' => [
                'sandbox' => [
                    'udev-demo' => 'demo-key',
                    'udev-central' => 'central-sandbox-key',
                ],
                'production' => [
                    'udev-central' => 'central-prod-key',
                ],
            ],
            'merchants' => [
                'sandbox' => [
                    'udev-demo' => ['name' => 'Demo Merchant', 'is_active' => true],
                    'udev-central' => ['name' => 'UDEV Central Sandbox', 'is_active' => true],
                ],
                'production' => [
                    'udev-central' => ['name' => 'UDEV Central Production', 'is_active' => true],
                ],
            ],
        ]);
        config()->set('services.udev_billing.subscription_dana_sandbox_merchant_code', 'udev-central');
        config()->set('services.udev_billing.subscription_dana_production_merchant_code', 'udev-central');
    }

    private function createLarissamaContext(): array
    {
        $app = Application::factory()->create([
            'code' => 'larissama',
            'name' => 'Larissama POS',
            'is_active' => true,
        ]);

        $rawToken = 'udev_global_'.fake()->uuid();
        ApplicationIntegrationToken::factory()->create([
            'application_id' => $app->id,
            'token_kind' => ApplicationIntegrationToken::KindGlobal,
            'token_hash' => hash('sha256', $rawToken),
        ]);

        $customer = ApplicationCustomer::factory()->create([
            'application_id' => $app->id,
            'external_customer_id' => 'WARUNG-LRS-001',
            'name' => 'Warung Barokah Larissama',
        ]);

        $plan = SubscriptionPlan::factory()->create([
            'application_id' => $app->id,
            'code' => 'PKT0002',
            'name' => 'Paket Usaha 1 Bulan',
            'price' => '10000.00',
            'is_active' => true,
        ]);

        $invoice = Invoice::factory()->create([
            'invoice_number' => 'INV-LRS-TEST-001',
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '10000.00',
            'currency' => 'IDR',
            'status' => Invoice::StatusPending,
            'due_at' => now()->addDay(),
        ]);

        return [$app, $rawToken, $customer, $plan, $invoice];
    }

    private function fakeDanaGateway(): void
    {
        Http::fake(function (HttpRequest $request): mixed {
            if ($request->method() === 'GET') {
                return Http::response(['data' => []]);
            }

            $data = $request->data();
            $orderId = (string) ($data['merchant_order_id'] ?? 'REF-001');
            $amount = (int) ($data['amount_minor'] ?? 10000);
            $method = (string) ($data['metadata']['payment_method'] ?? 'dana_wallet');

            $instruction = match ($method) {
                'qris' => [
                    'type' => 'qris_mpm',
                    'qr_content' => '00020101021226600016ID.DANA.WWW01189360091100000000005204581253033605802ID5912UDEV CENTRAL6007JAKARTA62070703A016304ABCD',
                    'payment_code' => 'DANA-QR-001',
                ],
                'va_bri' => [
                    'type' => 'virtual_account',
                    'bank' => 'BRI',
                    'payment_code' => '8881000011223344',
                ],
                'va_cimb' => [
                    'type' => 'virtual_account',
                    'bank' => 'CIMB',
                    'payment_code' => '8059000011223344',
                ],
                'va_btpn' => [
                    'type' => 'virtual_account',
                    'bank' => 'BTPN',
                    'payment_code' => '9001000011223344',
                ],
                'va_pani' => [
                    'type' => 'virtual_account',
                    'bank' => 'PANI',
                    'payment_code' => '7001000011223344',
                ],
                default => [
                    'type' => 'wallet',
                    'checkout_url' => 'https://checkout.dana.id/session/pay-001',
                ],
            };

            return Http::response([
                'data' => [
                    'id' => 'dana-pay-'.$orderId,
                    'merchant_order_id' => $orderId,
                    'amount_minor' => $amount,
                    'currency' => 'IDR',
                    'status' => 'pending',
                    'checkout_url' => $instruction['checkout_url'] ?? null,
                    'expires_at' => now()->addMinutes(30)->toISOString(),
                    'metadata' => [
                        'udev_sandbox_payment_instruction' => $instruction,
                        'qr_content' => $instruction['qr_content'] ?? null,
                    ],
                ],
            ]);
        });
    }

    public function test_checkout_supports_all_six_dana_methods_with_zero_admin_fee(): void
    {
        [$app, $token, $customer, $plan, $baseInvoice] = $this->createLarissamaContext();
        $this->fakeDanaGateway();

        $methods = [
            'qris' => ['bank' => null],
            'dana_wallet' => ['bank' => null],
            'va_bri' => ['bank' => 'bri'],
            'va_cimb' => ['bank' => 'cimb'],
            'va_btpn' => ['bank' => 'btpn'],
            'va_pani' => ['bank' => 'pani'],
        ];

        $idx = 1;
        foreach ($methods as $method => $params) {
            $invoice = Invoice::factory()->create([
                'invoice_number' => "INV-LRS-METHOD-{$idx}",
                'application_customer_id' => $customer->id,
                'subscription_plan_id' => $plan->id,
                'amount' => '10000.00',
                'currency' => 'IDR',
                'status' => Invoice::StatusPending,
                'due_at' => now()->addDay(),
            ]);

            $payload = [
                'provider' => 'dana',
                'payment_method' => $method,
            ];
            if ($params['bank'] !== null) {
                $payload['bank'] = $params['bank'];
            }

            $response = $this->withToken($token)
                ->postJson("/api/v1/integrations/invoices/{$invoice->invoice_number}/checkout", $payload, [
                    'Idempotency-Key' => "laris-chk-{$method}-{$idx}",
                ]);

            $response->assertOk()
                ->assertJsonPath('data.payment.provider', 'dana')
                ->assertJsonPath('data.payment.payment_method', $method)
                ->assertJsonPath('data.payment.checkout_status', 'ready');

            $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();
            $this->assertSame('dana', $attempt->provider);
            $this->assertSame('10000.00', (string) $attempt->amount);

            // Verify Rp 0 customer surcharge
            $feeBreakdown = $attempt->metadata['fee_breakdown'] ?? [];
            $this->assertEquals(0.0, (float) ($feeBreakdown['customer_fee'] ?? 0.0));
            $this->assertEquals('10000.00', (string) $attempt->amount);

            $idx++;
        }
    }

    public function test_bank_validation_allows_cimb_btpn_pani_and_rejects_invalid_bank(): void
    {
        [$app, $token, $customer, $plan, $invoice] = $this->createLarissamaContext();
        $this->fakeDanaGateway();

        // Valid banks (cimb, btpn, pani, bri)
        foreach (['cimb', 'btpn', 'pani', 'bri'] as $bank) {
            $testInvoice = Invoice::factory()->create([
                'invoice_number' => "INV-BANK-TEST-{$bank}",
                'application_customer_id' => $customer->id,
                'subscription_plan_id' => $plan->id,
                'amount' => '10000.00',
                'currency' => 'IDR',
                'status' => Invoice::StatusPending,
                'due_at' => now()->addDay(),
            ]);

            $this->withToken($token)
                ->postJson("/api/v1/integrations/invoices/{$testInvoice->invoice_number}/checkout", [
                    'provider' => 'dana',
                    'payment_method' => "va_{$bank}",
                    'bank' => $bank,
                ], ['Idempotency-Key' => "key-bank-{$bank}"])
                ->assertOk();
        }

        // Invalid bank
        $invalidInvoice = Invoice::factory()->create([
            'invoice_number' => 'INV-BANK-TEST-INVALID',
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '10000.00',
            'currency' => 'IDR',
            'status' => Invoice::StatusPending,
            'due_at' => now()->addDay(),
        ]);

        $this->withToken($token)
            ->postJson("/api/v1/integrations/invoices/{$invalidInvoice->invoice_number}/checkout", [
                'provider' => 'dana',
                'payment_method' => 'va_cimb',
                'bank' => 'invalid_bank_xyz',
            ], ['Idempotency-Key' => 'key-bank-invalid'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['bank']);
    }

    public function test_header_idempotency_prevents_duplicate_attempts_and_rejects_parameter_mutation(): void
    {
        [$app, $token, $customer, $plan, $invoice] = $this->createLarissamaContext();
        $this->fakeDanaGateway();

        $idempotencyKey = 'laris-idemp-test-001';

        // 1. First checkout
        $res1 = $this->withToken($token)
            ->postJson("/api/v1/integrations/invoices/{$invoice->invoice_number}/checkout", [
                'provider' => 'dana',
                'payment_method' => 'qris',
            ], ['Idempotency-Key' => $idempotencyKey]);

        $res1->assertOk();
        $this->assertDatabaseCount('payment_attempts', 1);
        $attemptId = PaymentAttempt::query()->first()->getKey();

        // 2. Retry with same idempotency key and identical parameters -> returns same attempt
        $res2 = $this->withToken($token)
            ->postJson("/api/v1/integrations/invoices/{$invoice->invoice_number}/checkout", [
                'provider' => 'dana',
                'payment_method' => 'qris',
            ], ['Idempotency-Key' => $idempotencyKey]);

        $res2->assertOk();
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertSame($attemptId, PaymentAttempt::query()->first()->getKey());

        // 3. Retry with same idempotency key but different parameter -> 409 Conflict
        $res3 = $this->withToken($token)
            ->postJson("/api/v1/integrations/invoices/{$invoice->invoice_number}/checkout", [
                'provider' => 'dana',
                'payment_method' => 'va_cimb',
            ], ['Idempotency-Key' => $idempotencyKey]);

        $res3->assertStatus(409);
        $this->assertStringContainsString('sudah digunakan untuk checkout', (string) $res3->json('message'));
        $this->assertDatabaseCount('payment_attempts', 1);
    }

    public function test_larissama_central_billing_routes_to_udev_central(): void
    {
        [$app, $token, $customer, $plan, $invoice] = $this->createLarissamaContext();
        $this->fakeDanaGateway();

        // Even if someone created a tenant payment gateway mapping for Larissama
        ApplicationPaymentGateway::create([
            'application_id' => $app->id,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'larissama-tenant-custom',
            'is_active' => true,
        ]);

        $response = $this->withToken($token)
            ->postJson("/api/v1/integrations/invoices/{$invoice->invoice_number}/checkout", [
                'provider' => 'dana',
                'payment_method' => 'qris',
            ], ['Idempotency-Key' => 'route-central-001']);

        $response->assertOk();

        $attempt = PaymentAttempt::query()->sole();
        // Recipient merchant must be udev-central (central billing for Larissama)
        $this->assertSame('udev-central', $attempt->gateway_merchant_code);
        $this->assertSame('udev_central', $attempt->metadata['billing_recipient'] ?? null);
    }

    public function test_non_larissama_tenant_application_routes_to_its_own_tenant_merchant(): void
    {
        $tenantApp = Application::factory()->create([
            'code' => 'smartlaundry',
            'name' => 'Smart Laundry POS',
            'is_active' => true,
        ]);

        $rawToken = 'udev_global_'.fake()->uuid();
        ApplicationIntegrationToken::factory()->create([
            'application_id' => $tenantApp->id,
            'token_kind' => ApplicationIntegrationToken::KindGlobal,
            'token_hash' => hash('sha256', $rawToken),
        ]);

        $customer = ApplicationCustomer::factory()->create([
            'application_id' => $tenantApp->id,
            'external_customer_id' => 'LAUNDRY-CUST-001',
        ]);

        $plan = SubscriptionPlan::factory()->create([
            'application_id' => $tenantApp->id,
            'code' => 'LND01',
            'price' => '25000.00',
        ]);

        $invoice = Invoice::factory()->create([
            'invoice_number' => 'INV-LND-001',
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '25000.00',
            'currency' => 'IDR',
            'status' => Invoice::StatusPending,
            'due_at' => now()->addDay(),
        ]);

        // Tenant mapping for non-larissama application
        config()->set('services.dana_gateway.merchants.sandbox.laundry-merchant', [
            'name' => 'Laundry Merchant',
            'is_active' => true,
        ]);
        config()->set('services.dana_gateway.merchant_api_keys.sandbox.laundry-merchant', 'laundry-key');

        ApplicationPaymentGateway::create([
            'application_id' => $tenantApp->id,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'laundry-merchant',
            'is_active' => true,
        ]);

        DanaPaymentFeeSetting::query()->create([
            'gateway_merchant_code' => 'laundry-merchant',
            'application_id' => $tenantApp->id,
            'payment_method' => 'qris',
            'environment' => 'sandbox',
            'fee_type' => 'dana',
            'fee_value' => 0.70,
            'fee_unit' => 'percent',
            'is_active' => true,
        ]);

        $this->fakeDanaGateway();

        $response = $this->withToken($rawToken)
            ->postJson("/api/v1/integrations/invoices/{$invoice->invoice_number}/checkout", [
                'provider' => 'dana',
                'payment_method' => 'qris',
            ], ['Idempotency-Key' => 'route-tenant-001']);

        $response->assertOk();

        $attempt = PaymentAttempt::query()->sole();
        // Recipient merchant must route to the tenant's own merchant code
        $this->assertSame('laundry-merchant', $attempt->gateway_merchant_code);
        $this->assertSame('application_customer', $attempt->metadata['billing_recipient'] ?? null);
    }
}
