<?php

namespace Tests\Feature;

use App\Models\PaymentAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentResultApiTest extends TestCase
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

    public function test_public_result_uses_gateway_status_and_never_trusts_redirect_status(): void
    {
        $paymentAttempt = $this->paymentAttempt();
        Http::fake([
            "https://gateway.test/api/v1/payments/{$paymentAttempt->gateway_payment_id}/status" => Http::response([
                'data' => $this->gatewayPayment($paymentAttempt, 'paid'),
            ]),
        ]);

        $this->getJson("/api/v1/payment-results/{$paymentAttempt->gateway_payment_id}?status=failed")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.reference', $paymentAttempt->gateway_payment_id)
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.amount', '10000.00')
            ->assertJsonMissingPath('data.invoice_number')
            ->assertJsonMissingPath('data.customer');

        $this->assertDatabaseHas('payment_attempts', [
            'id' => $paymentAttempt->id,
            'status' => PaymentAttempt::StatusSucceeded,
        ]);
        Http::assertSentCount(1);
    }

    public function test_public_result_returns_pending_then_expired_status_from_gateway(): void
    {
        $paymentAttempt = $this->paymentAttempt();
        Http::fake([
            "https://gateway.test/api/v1/payments/{$paymentAttempt->gateway_payment_id}/status" => Http::sequence()
                ->push(['data' => $this->gatewayPayment($paymentAttempt, 'pending')])
                ->push(['data' => $this->gatewayPayment($paymentAttempt, 'expired')]),
        ]);

        $this->getJson("/api/v1/payment-results/{$paymentAttempt->gateway_payment_id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $this->getJson("/api/v1/payment-results/{$paymentAttempt->gateway_payment_id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'expired');

        $this->assertDatabaseHas('payment_attempts', [
            'id' => $paymentAttempt->id,
            'status' => PaymentAttempt::StatusExpired,
        ]);
    }

    public function test_unknown_or_malformed_reference_does_not_disclose_payment_data(): void
    {
        $this->getJson('/api/v1/payment-results/'.Str::ulid())
            ->assertNotFound()
            ->assertJsonPath('message', 'Data yang Anda cari tidak ditemukan.');

        $this->getJson('/api/v1/payment-results/not-a-reference')
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_return_url_only_uses_registered_https_application_url(): void
    {
        $paymentAttempt = $this->paymentAttempt();
        $paymentAttempt->update([
            'status' => PaymentAttempt::StatusSucceeded,
            'completed_at' => now(),
        ]);
        $application = $paymentAttempt->invoice->plan->application;
        $application->update(['application_url' => 'https://larissama.example.test/billing']);

        $this->getJson("/api/v1/payment-results/{$paymentAttempt->gateway_payment_id}")
            ->assertOk()
            ->assertJsonPath('data.return_url', 'https://larissama.example.test/billing');

        $application->update(['application_url' => 'http://untrusted.example.test']);

        $this->getJson("/api/v1/payment-results/{$paymentAttempt->gateway_payment_id}")
            ->assertOk()
            ->assertJsonPath('data.return_url', null);

        Http::assertNothingSent();
    }

    private function paymentAttempt(): PaymentAttempt
    {
        return PaymentAttempt::factory()->create([
            'amount' => '10000.00',
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusPending,
            'merchant_reference' => 'INV-RESULT-A1',
            'gateway_merchant_code' => 'udev-demo',
            'gateway_environment' => 'sandbox',
            'gateway_payment_id' => (string) Str::ulid(),
        ]);
    }

    /** @return array<string, mixed> */
    private function gatewayPayment(PaymentAttempt $paymentAttempt, string $status): array
    {
        return [
            'id' => $paymentAttempt->gateway_payment_id,
            'merchant_order_id' => $paymentAttempt->merchant_reference,
            'amount_minor' => 10000,
            'currency' => 'IDR',
            'status' => $status,
            'paid_at' => $status === 'paid' ? now()->toISOString() : null,
        ];
    }
}
