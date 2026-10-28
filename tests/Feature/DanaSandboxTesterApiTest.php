<?php

namespace Tests\Feature;

use App\Models\DanaMerchantProvisioningAudit;
use App\Models\DanaSandboxResultContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DanaSandboxTesterApiTest extends TestCase
{
    use RefreshDatabase;

    private const string ServiceToken = 'test-sandbox-tester-service-token';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.dana_merchant_provisioning', [
            'base_url' => 'https://dana-gateway.test',
            'service_token' => self::ServiceToken,
            'timeout' => 8,
            'connect_timeout' => 3,
        ]);
        Http::preventStrayRequests();
    }

    public function test_tester_endpoints_require_authorized_admin_and_known_sandbox_merchant(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();

        $this->getJson($this->validationUrl($merchant->gateway_merchant_id))->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(), ['admin:access']);
        $this->getJson($this->validationUrl($merchant->gateway_merchant_id))->assertForbidden();

        $this->actingAdmin();
        $this->getJson($this->validationUrl(999999))->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_tester_rejects_payment_method_outside_the_verified_sandbox_allowlist(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        $this->actingAdmin();

        $this->withHeader('Idempotency-Key', 'frontend-sandbox-tester-unverified-method')
            ->postJson($this->transactionUrl($merchant->gateway_merchant_id), ['amount_minor' => 10_000, 'payment_method' => 'ovo'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');

        Http::assertNothingSent();
    }

    public function test_tester_exposes_only_allowlisted_sandbox_validation_messages_from_gateway(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        $calls = 0;
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/transactions" => function () use (&$calls) {
                $calls++;

                return Http::response([
                    'message' => $calls === 1
                        ? 'QRIS Sandbox memerlukan External Store ID pada konfigurasi merchant.'
                        : 'Pesan internal yang tidak boleh diteruskan.',
                ], 422);
            },
        ]);
        $this->actingAdmin();

        $this->withHeader('Idempotency-Key', 'frontend-sandbox-tester-qris-no-store')
            ->postJson($this->transactionUrl($merchant->gateway_merchant_id), ['amount_minor' => 10_000, 'payment_method' => 'qris'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'QRIS Sandbox memerlukan External Store ID pada konfigurasi merchant.');

        $this->withHeader('Idempotency-Key', 'frontend-sandbox-tester-unknown-422')
            ->postJson($this->transactionUrl($merchant->gateway_merchant_id), ['amount_minor' => 10_000, 'payment_method' => 'dana_wallet'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Data transaksi Sandbox ditolak oleh gateway.')
            ->assertDontSee('Pesan internal yang tidak boleh diteruskan.');
    }

    public function test_tester_maps_gateway_transaction_conflicts_without_calling_them_provisioning_conflicts(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/transactions" => Http::response([
                'message' => 'Idempotency-Key sudah digunakan untuk payload yang berbeda.',
            ], 409),
        ]);
        $this->actingAdmin();

        $this->withHeader('Idempotency-Key', 'frontend-sandbox-tester-conflict-001')
            ->postJson($this->transactionUrl($merchant->gateway_merchant_id), ['amount_minor' => 10_000, 'payment_method' => 'va_bri'])
            ->assertConflict()
            ->assertJsonPath('message', 'Konflik idempotensi transaksi Sandbox: key hanya dapat dipakai ulang untuk payload yang identik.')
            ->assertDontSee('provisioning merchant');

        $this->assertDatabaseHas('dana_merchant_provisioning_audits', [
            'gateway_merchant_id' => $merchant->gateway_merchant_id,
            'operation' => 'dana_sandbox_tester.transaction_created',
            'status' => 'failed',
            'gateway_status' => 409,
        ]);
        $this->assertSame('idempotency_conflict', DanaMerchantProvisioningAudit::query()->latest('id')->value('safe_details')['error_context']);
    }

    public function test_tester_distinguishes_a_merchant_order_conflict_from_an_idempotency_conflict(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/transactions" => Http::response([
                'message' => 'merchant_order_id sudah digunakan.',
            ], 409),
        ]);
        $this->actingAdmin();

        $this->withHeader('Idempotency-Key', 'frontend-sandbox-tester-order-conflict-001')
            ->postJson($this->transactionUrl($merchant->gateway_merchant_id), ['amount_minor' => 10_000, 'payment_method' => 'va_bri'])
            ->assertConflict()
            ->assertJsonPath('message', 'Konflik referensi transaksi Sandbox: referensi order sudah digunakan oleh transaksi lain.');
    }

    public function test_validation_only_returns_safe_configuration_metadata_and_audits_the_result(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        $privateKey = 'private-key-must-not-leave-gateway';
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/validation" => Http::response([
                'data' => $this->validation($merchant->merchant_code, $privateKey),
            ]),
        ]);
        $this->actingAdmin();

        $this->getJson($this->validationUrl($merchant->gateway_merchant_id))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.format_valid', true)
            ->assertJsonPath('data.private_key.format_valid', true)
            ->assertJsonPath('data.dana_webhook_public_key.format_valid', true)
            ->assertJsonMissingPath('data.private_key.value')
            ->assertDontSee($privateKey);

        $this->assertDatabaseHas('dana_merchant_provisioning_audits', [
            'gateway_merchant_id' => $merchant->gateway_merchant_id,
            'operation' => 'dana_sandbox_tester.configuration_validated',
            'status' => 'succeeded',
        ]);
    }

    public function test_missing_timestamp_uat_returns_only_safe_dana_diagnostics(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/uat/create-order-missing-timestamp" => Http::response([
                'data' => [
                    'merchant_code' => $merchant->merchant_code,
                    'environment' => 'sandbox',
                    'uat' => [
                        'scenario' => 'create_order_missing_x_timestamp',
                        'passed' => true,
                        'expected' => ['response_code' => '4005402', 'response_message' => 'Invalid Mandatory Field X-TIMESTAMP'],
                        'received' => ['http_status' => 400, 'response_code' => '4005402', 'response_message' => 'Invalid Mandatory Field X-TIMESTAMP', 'request_id' => 'uat-missing-timestamp-001'],
                        'request' => ['x_timestamp_sent' => false],
                    ],
                ],
            ]),
        ]);

        $this->actingAdmin();
        $this->postJson($this->missingTimestampUatUrl($merchant->gateway_merchant_id))
            ->assertOk()
            ->assertJsonPath('data.uat.passed', true)
            ->assertJsonPath('data.uat.received.response_code', '4005402')
            ->assertJsonPath('data.uat.received.response_message', 'Invalid Mandatory Field X-TIMESTAMP')
            ->assertJsonPath('data.uat.request.x_timestamp_sent', false)
            ->assertJsonMissing(['private_key', 'api_key', 'signature']);
    }

    public function test_invalid_amount_format_uat_returns_only_safe_dana_diagnostics(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/uat/create-order-invalid-amount-format" => Http::response([
                'data' => [
                    'merchant_code' => $merchant->merchant_code,
                    'environment' => 'sandbox',
                    'uat' => [
                        'scenario' => 'create_order_invalid_amount_format',
                        'passed' => true,
                        'expected' => ['response_code' => '4005401', 'response_message' => 'Invalid Field Format amount'],
                        'received' => ['http_status' => 400, 'response_code' => '4005401', 'response_message' => 'Invalid Field Format amount', 'request_id' => 'uat-invalid-amount-001'],
                        'request' => ['amount_format_valid' => false],
                    ],
                ],
            ]),
        ]);

        $this->actingAdmin();
        $this->postJson($this->invalidAmountFormatUatUrl($merchant->gateway_merchant_id))
            ->assertOk()
            ->assertJsonPath('data.uat.passed', true)
            ->assertJsonPath('data.uat.received.response_code', '4005401')
            ->assertJsonPath('data.uat.received.response_message', 'Invalid Field Format amount')
            ->assertJsonPath('data.uat.request.amount_format_valid', false)
            ->assertJsonMissing(['private_key', 'api_key', 'signature']);
    }

    public function test_ipg_create_order_success_uat_returns_safe_dana_diagnostics_and_audits(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/uat/ipg-create-order-success" => Http::response([
                'data' => [
                    'merchant_code' => $merchant->merchant_code,
                    'environment' => 'sandbox',
                    'uat' => [
                        'scenario' => 'ipg_create_order_success',
                        'passed' => true,
                        'expected' => ['response_code' => '2005400', 'response_message' => 'Successful'],
                        'received' => [
                            'http_status' => 200,
                            'response_code' => '2005400',
                            'response_message' => 'Successful',
                            'reference_no' => '2026102877770000000001',
                            'web_redirect_url' => 'https://sandbox-gateway.partner.co.id/checkout?token=ipg-token-001',
                            'request_id' => 'uat-ipg-success-001',
                        ],
                        'request' => [
                            'endpoint' => '/rest/redirection/v1.0/debit/payment-host-to-host',
                            'x_timestamp_sent' => true,
                        ],
                    ],
                ],
            ]),
        ]);

        $this->actingAdmin();
        $this->postJson($this->ipgCreateOrderSuccessUatUrl($merchant->gateway_merchant_id))
            ->assertOk()
            ->assertJsonPath('data.uat.passed', true)
            ->assertJsonPath('data.uat.received.response_code', '2005400')
            ->assertJsonPath('data.uat.received.response_message', 'Successful')
            ->assertJsonPath('data.uat.received.reference_no', '2026102877770000000001')
            ->assertJsonPath('data.uat.received.web_redirect_url', 'https://sandbox-gateway.partner.co.id/checkout?token=ipg-token-001')
            ->assertJsonPath('data.uat.request.endpoint', '/rest/redirection/v1.0/debit/payment-host-to-host')
            ->assertJsonPath('data.uat.request.x_timestamp_sent', true)
            ->assertJsonMissing(['private_key', 'api_key', 'signature']);

        $audit = DanaMerchantProvisioningAudit::query()
            ->where('operation', 'dana_sandbox_tester.uat_ipg_create_order_success')
            ->sole();
        $this->assertSame('succeeded', $audit->status);
        $this->assertSame($merchant->merchant_code, $audit->merchant_code);
        $this->assertSame($merchant->gateway_merchant_id, $audit->gateway_merchant_id);
        $this->assertSame('/rest/redirection/v1.0/debit/payment-host-to-host', $audit->safe_details['endpoint']);
        $this->assertTrue($audit->safe_details['passed']);
    }

    public function test_ipg_missing_timestamp_uat_returns_safe_dana_diagnostics_and_audits(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/uat/ipg-create-order-missing-timestamp" => Http::response([
                'data' => [
                    'merchant_code' => $merchant->merchant_code,
                    'environment' => 'sandbox',
                    'uat' => [
                        'scenario' => 'ipg_create_order_missing_x_timestamp',
                        'passed' => true,
                        'expected' => ['response_code' => '4005402', 'response_message' => 'Invalid Mandatory Field X-TIMESTAMP'],
                        'received' => [
                            'http_status' => 400,
                            'response_code' => '4005402',
                            'response_message' => 'Invalid Mandatory Field X-TIMESTAMP',
                            'request_id' => 'uat-ipg-missing-ts-001',
                        ],
                        'request' => [
                            'endpoint' => '/rest/redirection/v1.0/debit/payment-host-to-host',
                            'x_timestamp_sent' => false,
                        ],
                    ],
                ],
            ]),
        ]);

        $this->actingAdmin();
        $this->postJson($this->ipgMissingTimestampUatUrl($merchant->gateway_merchant_id))
            ->assertOk()
            ->assertJsonPath('data.uat.passed', true)
            ->assertJsonPath('data.uat.received.response_code', '4005402')
            ->assertJsonPath('data.uat.received.response_message', 'Invalid Mandatory Field X-TIMESTAMP')
            ->assertJsonPath('data.uat.request.endpoint', '/rest/redirection/v1.0/debit/payment-host-to-host')
            ->assertJsonPath('data.uat.request.x_timestamp_sent', false)
            ->assertJsonMissing(['private_key', 'api_key', 'signature']);

        $audit = DanaMerchantProvisioningAudit::query()
            ->where('operation', 'dana_sandbox_tester.uat_ipg_create_order_missing_timestamp')
            ->sole();
        $this->assertSame('succeeded', $audit->status);
        $this->assertSame($merchant->merchant_code, $audit->merchant_code);
        $this->assertSame($merchant->gateway_merchant_id, $audit->gateway_merchant_id);
        $this->assertSame('/rest/redirection/v1.0/debit/payment-host-to-host', $audit->safe_details['endpoint']);
        $this->assertTrue($audit->safe_details['passed']);
    }

    public function test_ipg_general_error_uat_returns_safe_dana_diagnostics_and_audits(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/uat/ipg-create-order-general-error" => Http::response([
                'data' => [
                    'merchant_code' => $merchant->merchant_code,
                    'environment' => 'sandbox',
                    'uat' => [
                        'scenario' => 'ipg_create_order_general_error',
                        'passed' => true,
                        'expected' => ['response_code' => '5005400', 'response_message' => 'General Error'],
                        'received' => [
                            'http_status' => 500,
                            'response_code' => '5005400',
                            'response_message' => 'General Error',
                            'request_id' => 'uat-ipg-general-err-001',
                        ],
                        'request' => [
                            'endpoint' => '/rest/redirection/v1.0/debit/payment-host-to-host',
                            'x_timestamp_sent' => true,
                            'amount_value' => '505400.00',
                            'product_code' => '51051000100000000001',
                            'support_deep_link_checkout_url' => 'true',
                        ],
                    ],
                ],
            ]),
        ]);

        $this->actingAdmin();
        $this->postJson($this->ipgGeneralErrorUatUrl($merchant->gateway_merchant_id))
            ->assertOk()
            ->assertJsonPath('data.uat.passed', true)
            ->assertJsonPath('data.uat.received.http_status', 500)
            ->assertJsonPath('data.uat.received.response_code', '5005400')
            ->assertJsonPath('data.uat.received.response_message', 'General Error')
            ->assertJsonPath('data.uat.request.endpoint', '/rest/redirection/v1.0/debit/payment-host-to-host')
            ->assertJsonPath('data.uat.request.amount_value', '505400.00')
            ->assertJsonPath('data.uat.request.product_code', '51051000100000000001')
            ->assertJsonMissing(['private_key', 'api_key', 'signature']);

        $audit = DanaMerchantProvisioningAudit::query()
            ->where('operation', 'dana_sandbox_tester.uat_ipg_create_order_general_error')
            ->sole();
        $this->assertSame('failed', $audit->status);
        $this->assertSame($merchant->merchant_code, $audit->merchant_code);
        $this->assertSame($merchant->gateway_merchant_id, $audit->gateway_merchant_id);
        $this->assertSame('/rest/redirection/v1.0/debit/payment-host-to-host', $audit->safe_details['endpoint']);
        $this->assertSame('505400.00', $audit->safe_details['amount_value']);
        $this->assertSame('FAILED', $audit->safe_details['transaction_status']);
        $this->assertTrue($audit->safe_details['passed']);
    }

    public function test_ipg_transaction_not_permitted_uat_returns_safe_dana_diagnostics_and_audits(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/uat/ipg-create-order-transaction-not-permitted" => Http::response([
                'data' => [
                    'merchant_code' => $merchant->merchant_code,
                    'environment' => 'sandbox',
                    'uat' => [
                        'scenario' => 'ipg_create_order_transaction_not_permitted',
                        'passed' => true,
                        'expected' => ['response_code' => '4035415', 'response_message' => 'Transaction Not Permitted'],
                        'received' => [
                            'http_status' => 403,
                            'response_code' => '4035415',
                            'response_message' => 'Transaction Not Permitted',
                            'request_id' => 'dana-req-4035415-001',
                        ],
                        'request' => [
                            'endpoint' => '/rest/redirection/v1.0/debit/payment-host-to-host',
                            'x_timestamp_sent' => true,
                            'amount_value' => '435415.00',
                            'product_code' => '51051000100000000001',
                            'support_deep_link_checkout_url' => 'true',
                        ],
                    ],
                ],
            ]),
        ]);

        $this->actingAdmin();
        $this->postJson($this->ipgTransactionNotPermittedUatUrl($merchant->gateway_merchant_id))
            ->assertOk()
            ->assertJsonPath('data.uat.passed', true)
            ->assertJsonPath('data.uat.received.http_status', 403)
            ->assertJsonPath('data.uat.received.response_code', '4035415')
            ->assertJsonPath('data.uat.received.response_message', 'Transaction Not Permitted')
            ->assertJsonPath('data.uat.request.endpoint', '/rest/redirection/v1.0/debit/payment-host-to-host')
            ->assertJsonPath('data.uat.request.amount_value', '435415.00')
            ->assertJsonPath('data.uat.request.product_code', '51051000100000000001')
            ->assertJsonMissing(['private_key', 'api_key', 'signature']);

        $audit = DanaMerchantProvisioningAudit::query()
            ->where('operation', 'dana_sandbox_tester.uat_ipg_create_order_transaction_not_permitted')
            ->sole();
        $this->assertSame('failed', $audit->status);
        $this->assertSame($merchant->merchant_code, $audit->merchant_code);
        $this->assertSame($merchant->gateway_merchant_id, $audit->gateway_merchant_id);
        $this->assertSame('/rest/redirection/v1.0/debit/payment-host-to-host', $audit->safe_details['endpoint']);
        $this->assertSame('435415.00', $audit->safe_details['amount_value']);
        $this->assertSame('FAILED', $audit->safe_details['transaction_status']);
        $this->assertTrue($audit->safe_details['passed']);
    }

    public function test_ipg_invalid_merchant_uat_returns_safe_dana_diagnostics_and_audits(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/uat/ipg-create-order-invalid-merchant" => Http::response([
                'data' => [
                    'merchant_code' => $merchant->merchant_code,
                    'environment' => 'sandbox',
                    'uat' => [
                        'scenario' => 'ipg_create_order_invalid_merchant',
                        'passed' => true,
                        'expected' => ['response_code' => '4045408', 'response_message' => 'Invalid Merchant'],
                        'received' => [
                            'http_status' => 404,
                            'response_code' => '4045408',
                            'response_message' => 'Invalid Merchant',
                            'request_id' => 'dana-req-4045408-001',
                        ],
                        'request' => [
                            'endpoint' => '/rest/redirection/v1.0/debit/payment-host-to-host',
                            'x_timestamp_sent' => true,
                            'amount_value' => '445408.00',
                            'product_code' => '51051000100000000001',
                            'support_deep_link_checkout_url' => 'true',
                        ],
                    ],
                ],
            ]),
        ]);

        $this->actingAdmin();
        $this->postJson($this->ipgInvalidMerchantUatUrl($merchant->gateway_merchant_id))
            ->assertOk()
            ->assertJsonPath('data.uat.passed', true)
            ->assertJsonPath('data.uat.received.http_status', 404)
            ->assertJsonPath('data.uat.received.response_code', '4045408')
            ->assertJsonPath('data.uat.received.response_message', 'Invalid Merchant')
            ->assertJsonPath('data.uat.request.endpoint', '/rest/redirection/v1.0/debit/payment-host-to-host')
            ->assertJsonPath('data.uat.request.amount_value', '445408.00')
            ->assertJsonPath('data.uat.request.product_code', '51051000100000000001')
            ->assertJsonMissing(['private_key', 'api_key', 'signature']);

        $audit = DanaMerchantProvisioningAudit::query()
            ->where('operation', 'dana_sandbox_tester.uat_ipg_create_order_invalid_merchant')
            ->sole();
        $this->assertSame('failed', $audit->status);
        $this->assertSame($merchant->merchant_code, $audit->merchant_code);
        $this->assertSame($merchant->gateway_merchant_id, $audit->gateway_merchant_id);
        $this->assertSame('/rest/redirection/v1.0/debit/payment-host-to-host', $audit->safe_details['endpoint']);
        $this->assertSame('445408.00', $audit->safe_details['amount_value']);
        $this->assertSame('FAILED', $audit->safe_details['transaction_status']);
        $this->assertTrue($audit->safe_details['passed']);
    }

    public function test_ipg_inconsistent_request_uat_returns_safe_dana_diagnostics_and_audits(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/uat/ipg-create-order-inconsistent-request" => Http::response([
                'data' => [
                    'merchant_code' => $merchant->merchant_code,
                    'environment' => 'sandbox',
                    'uat' => [
                        'scenario' => 'ipg_create_order_inconsistent_request',
                        'passed' => true,
                        'expected' => ['response_code' => '4045418', 'response_message' => 'Inconsistent Request'],
                        'first_request' => [
                            'amount' => '10000.00',
                            'http_status' => 200,
                            'response_code' => '2005400',
                            'response_message' => 'Successful',
                            'request_id' => 'uat-ipg-inc-001',
                        ],
                        'second_request' => [
                            'amount' => '20000.00',
                            'http_status' => 404,
                            'response_code' => '4045418',
                            'response_message' => 'Inconsistent Request',
                            'request_id' => 'uat-ipg-inc-002',
                        ],
                        'received' => [
                            'http_status' => 404,
                            'response_code' => '4045418',
                            'response_message' => 'Inconsistent Request',
                            'request_id' => 'uat-ipg-inc-002',
                        ],
                        'request' => [
                            'endpoint' => '/rest/redirection/v1.0/debit/payment-host-to-host',
                            'partner_reference_no' => 'PARTNER-REF-IPG-INC-001',
                            'first_amount' => '10000.00',
                            'second_amount' => '20000.00',
                            'product_code' => '51051000100000000001',
                            'support_deep_link_checkout_url' => 'true',
                        ],
                    ],
                ],
            ]),
        ]);

        $this->actingAdmin();
        $this->postJson($this->ipgInconsistentRequestUatUrl($merchant->gateway_merchant_id))
            ->assertOk()
            ->assertJsonPath('data.uat.passed', true)
            ->assertJsonPath('data.uat.first_request.response_code', '2005400')
            ->assertJsonPath('data.uat.first_request.amount', '10000.00')
            ->assertJsonPath('data.uat.second_request.response_code', '4045418')
            ->assertJsonPath('data.uat.second_request.amount', '20000.00')
            ->assertJsonPath('data.uat.request.endpoint', '/rest/redirection/v1.0/debit/payment-host-to-host')
            ->assertJsonPath('data.uat.request.product_code', '51051000100000000001')
            ->assertJsonMissing(['private_key', 'api_key', 'signature']);

        $audit = DanaMerchantProvisioningAudit::query()
            ->where('operation', 'dana_sandbox_tester.uat_ipg_create_order_inconsistent_request')
            ->sole();
        $this->assertSame('failed', $audit->status);
        $this->assertSame($merchant->merchant_code, $audit->merchant_code);
        $this->assertSame($merchant->gateway_merchant_id, $audit->gateway_merchant_id);
        $this->assertSame('/rest/redirection/v1.0/debit/payment-host-to-host', $audit->safe_details['endpoint']);
        $this->assertSame('PARTNER-REF-IPG-INC-001', $audit->safe_details['partner_reference_no']);
        $this->assertSame('10000.00', $audit->safe_details['first_amount']);
        $this->assertSame('20000.00', $audit->safe_details['second_amount']);
        $this->assertSame('FAILED', $audit->safe_details['transaction_status']);
        $this->assertTrue($audit->safe_details['passed']);
    }

    public function test_ipg_invalid_signature_uat_returns_safe_dana_diagnostics_and_audits(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/uat/ipg-create-order-invalid-signature" => Http::response([
                'data' => [
                    'merchant_code' => $merchant->merchant_code,
                    'environment' => 'sandbox',
                    'uat' => [
                        'scenario' => 'ipg_create_order_invalid_signature',
                        'passed' => true,
                        'expected' => ['response_code' => '4015400', 'response_message' => 'Unauthorized'],
                        'received' => [
                            'http_status' => 401,
                            'response_code' => '4015400',
                            'response_message' => 'Unauthorized. [Signature check failed]',
                            'request_id' => 'uat-ipg-sig-001',
                        ],
                        'request' => [
                            'endpoint' => '/rest/redirection/v1.0/debit/payment-host-to-host',
                            'x_timestamp_sent' => true,
                            'amount_value' => '10000.00',
                            'product_code' => '51051000100000000001',
                            'support_deep_link_checkout_url' => 'true',
                            'signature_strategy' => 'transient_rsa_private_key',
                        ],
                    ],
                ],
            ]),
        ]);

        $this->actingAdmin();
        $this->postJson($this->ipgInvalidSignatureUatUrl($merchant->gateway_merchant_id))
            ->assertOk()
            ->assertJsonPath('data.uat.passed', true)
            ->assertJsonPath('data.uat.received.http_status', 401)
            ->assertJsonPath('data.uat.received.response_code', '4015400')
            ->assertJsonPath('data.uat.received.response_message', 'Unauthorized. [Signature check failed]')
            ->assertJsonPath('data.uat.request.endpoint', '/rest/redirection/v1.0/debit/payment-host-to-host')
            ->assertJsonPath('data.uat.request.amount_value', '10000.00')
            ->assertJsonPath('data.uat.request.product_code', '51051000100000000001')
            ->assertJsonMissing(['private_key', 'api_key', 'signature']);

        $audit = DanaMerchantProvisioningAudit::query()
            ->where('operation', 'dana_sandbox_tester.uat_ipg_create_order_invalid_signature')
            ->sole();
        $this->assertSame('failed', $audit->status);
        $this->assertSame($merchant->merchant_code, $audit->merchant_code);
        $this->assertSame($merchant->gateway_merchant_id, $audit->gateway_merchant_id);
        $this->assertSame('/rest/redirection/v1.0/debit/payment-host-to-host', $audit->safe_details['endpoint']);
        $this->assertSame('10000.00', $audit->safe_details['amount_value']);
        $this->assertSame('FAILED', $audit->safe_details['transaction_status']);
        $this->assertTrue($audit->safe_details['passed']);
    }

    public function test_ipg_exceeds_amount_limit_uat_returns_safe_dana_diagnostics_and_audits(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/uat/ipg-create-order-exceeds-amount-limit" => Http::response([
                'data' => [
                    'merchant_code' => $merchant->merchant_code,
                    'environment' => 'sandbox',
                    'uat' => [
                        'scenario' => 'ipg_create_order_exceeds_amount_limit',
                        'passed' => true,
                        'expected' => ['response_code' => '4035402', 'response_message' => 'Exceeds Transaction Amount Limit'],
                        'received' => [
                            'http_status' => 403,
                            'response_code' => '4035402',
                            'response_message' => 'Exceeds Transaction Amount Limit',
                            'request_id' => 'uat-ipg-limit-001',
                        ],
                        'request' => [
                            'endpoint' => '/rest/redirection/v1.0/debit/payment-host-to-host',
                            'x_timestamp_sent' => true,
                            'amount_value' => '5000000000.00',
                            'product_code' => '51051000100000000001',
                            'support_deep_link_checkout_url' => 'true',
                        ],
                    ],
                ],
            ]),
        ]);

        $this->actingAdmin();
        $this->postJson($this->ipgExceedsAmountLimitUatUrl($merchant->gateway_merchant_id))
            ->assertOk()
            ->assertJsonPath('data.uat.passed', true)
            ->assertJsonPath('data.uat.received.http_status', 403)
            ->assertJsonPath('data.uat.received.response_code', '4035402')
            ->assertJsonPath('data.uat.received.response_message', 'Exceeds Transaction Amount Limit')
            ->assertJsonPath('data.uat.request.endpoint', '/rest/redirection/v1.0/debit/payment-host-to-host')
            ->assertJsonPath('data.uat.request.amount_value', '5000000000.00')
            ->assertJsonPath('data.uat.request.product_code', '51051000100000000001')
            ->assertJsonMissing(['private_key', 'api_key', 'signature']);

        $audit = DanaMerchantProvisioningAudit::query()
            ->where('operation', 'dana_sandbox_tester.uat_ipg_create_order_exceeds_amount_limit')
            ->sole();
        $this->assertSame('failed', $audit->status);
        $this->assertSame($merchant->merchant_code, $audit->merchant_code);
        $this->assertSame($merchant->gateway_merchant_id, $audit->gateway_merchant_id);
        $this->assertSame('/rest/redirection/v1.0/debit/payment-host-to-host', $audit->safe_details['endpoint']);
        $this->assertSame('5000000000.00', $audit->safe_details['amount_value']);
        $this->assertSame('FAILED', $audit->safe_details['transaction_status']);
        $this->assertTrue($audit->safe_details['passed']);
    }

    public function test_inconsistent_request_uat_returns_only_safe_dana_diagnostics(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/uat/create-order-inconsistent-request" => Http::response([
                'data' => [
                    'merchant_code' => $merchant->merchant_code,
                    'environment' => 'sandbox',
                    'uat' => [
                        'scenario' => 'create_order_inconsistent_request',
                        'passed' => true,
                        'expected' => ['response_code' => '4045418', 'response_message' => 'Inconsistent Request'],
                        'first_request' => [
                            'amount' => '100000.00',
                            'http_status' => 200,
                            'response_code' => '2005400',
                            'response_message' => 'Successful',
                            'request_id' => 'uat-inc-req-001',
                        ],
                        'second_request' => [
                            'amount' => '200000.00',
                            'http_status' => 404,
                            'response_code' => '4045418',
                            'response_message' => 'Inconsistent Request',
                            'request_id' => 'uat-inc-req-002',
                        ],
                        'received' => [
                            'amount' => '200000.00',
                            'http_status' => 404,
                            'response_code' => '4045418',
                            'response_message' => 'Inconsistent Request',
                            'request_id' => 'uat-inc-req-002',
                        ],
                    ],
                ],
            ]),
        ]);

        $this->actingAdmin();
        $this->postJson($this->inconsistentRequestUatUrl($merchant->gateway_merchant_id))
            ->assertOk()
            ->assertJsonPath('data.uat.passed', true)
            ->assertJsonPath('data.uat.first_request.response_code', '2005400')
            ->assertJsonPath('data.uat.first_request.amount', '100000.00')
            ->assertJsonPath('data.uat.second_request.response_code', '4045418')
            ->assertJsonPath('data.uat.second_request.response_message', 'Inconsistent Request')
            ->assertJsonPath('data.uat.second_request.amount', '200000.00')
            ->assertJsonMissing(['private_key', 'api_key', 'signature']);
    }

    public function test_debit_status_transaction_not_found_uat_returns_safe_diagnostics_and_audits(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/uat/debit-status-transaction-not-found" => Http::response([
                'data' => [
                    'merchant_code' => $merchant->merchant_code,
                    'environment' => 'sandbox',
                    'uat' => [
                        'scenario' => 'debit_status_transaction_not_found',
                        'passed' => true,
                        'expected' => ['response_code' => '4045501', 'response_message' => 'Transaction Not Found'],
                        'received' => [
                            'http_status' => 404,
                            'response_code' => '4045501',
                            'response_message' => 'Transaction Not Found',
                            'request_id' => 'dana-notfound-001',
                        ],
                        'request' => [
                            'original_partner_reference_no' => '12345678',
                            'service_code' => '54',
                        ],
                    ],
                ],
            ]),
        ]);

        $this->actingAdmin();
        $this->postJson($this->debitStatusNotFoundUatUrl($merchant->gateway_merchant_id))
            ->assertOk()
            ->assertJsonPath('data.uat.passed', true)
            ->assertJsonPath('data.uat.scenario', 'debit_status_transaction_not_found')
            ->assertJsonPath('data.uat.expected.response_code', '4045501')
            ->assertJsonPath('data.uat.received.http_status', 404)
            ->assertJsonPath('data.uat.received.response_code', '4045501')
            ->assertJsonPath('data.uat.received.response_message', 'Transaction Not Found')
            ->assertJsonPath('data.uat.request.original_partner_reference_no', '12345678')
            ->assertJsonMissing(['private_key', 'api_key', 'signature']);

        $this->assertDatabaseHas('dana_merchant_provisioning_audits', [
            'gateway_merchant_id' => $merchant->gateway_merchant_id,
            'operation' => 'dana_sandbox_tester.uat_debit_status_not_found',
            'status' => 'succeeded',
            'safe_details->passed' => true,
            'safe_details->response_code' => '4045501',
            'safe_details->original_partner_reference_no' => '12345678',
        ]);
    }

    public function test_debit_status_missing_timestamp_uat_returns_safe_diagnostics_and_audits(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/uat/debit-status-missing-timestamp" => Http::response([
                'data' => [
                    'merchant_code' => $merchant->merchant_code,
                    'environment' => 'sandbox',
                    'uat' => [
                        'scenario' => 'debit_status_missing_x_timestamp',
                        'passed' => true,
                        'expected' => ['response_code' => '4005502', 'response_message' => 'Invalid Mandatory Field X-TIMESTAMP'],
                        'received' => [
                            'http_status' => 400,
                            'response_code' => '4005502',
                            'response_message' => 'Invalid Mandatory Field X-TIMESTAMP',
                            'request_id' => 'dana-notimestamp-001',
                        ],
                        'request' => [
                            'x_timestamp_sent' => false,
                            'service_code' => '54',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->actingAdmin();

        $this->postJson($this->debitStatusMissingTimestampUatUrl($merchant->gateway_merchant_id))
            ->assertOk()
            ->assertJsonPath('data.uat.passed', true)
            ->assertJsonPath('data.uat.scenario', 'debit_status_missing_x_timestamp')
            ->assertJsonPath('data.uat.expected.response_code', '4005502')
            ->assertJsonPath('data.uat.received.http_status', 400)
            ->assertJsonPath('data.uat.received.response_code', '4005502')
            ->assertJsonPath('data.uat.received.response_message', 'Invalid Mandatory Field X-TIMESTAMP')
            ->assertJsonPath('data.uat.request.x_timestamp_sent', false)
            ->assertJsonPath('data.uat.request.service_code', '54')
            ->assertJsonMissing(['private_key', 'api_key', 'signature']);

        $this->assertDatabaseHas('dana_merchant_provisioning_audits', [
            'gateway_merchant_id' => $merchant->gateway_merchant_id,
            'operation' => 'dana_sandbox_tester.uat_debit_status_missing_timestamp',
            'status' => 'succeeded',
            'safe_details->passed' => true,
            'safe_details->response_code' => '4005502',
            'safe_details->x_timestamp_sent' => false,
        ]);
    }

    public function test_debit_status_internal_server_error_uat_returns_safe_diagnostics_and_audits(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/uat/debit-status-internal-server-error" => Http::response([
                'data' => [
                    'merchant_code' => $merchant->merchant_code,
                    'environment' => 'sandbox',
                    'uat' => [
                        'scenario' => 'debit_status_internal_server_error',
                        'passed' => true,
                        'expected' => ['response_code' => '5005501', 'response_message' => 'Internal Server Error'],
                        'received' => [
                            'http_status' => 500,
                            'response_code' => '5005501',
                            'response_message' => 'Internal Server Error',
                            'request_id' => 'dana-server-err-001',
                        ],
                        'request' => [
                            'service_code' => 'AZ',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->actingAdmin();

        $this->postJson($this->debitStatusInternalServerErrorUatUrl($merchant->gateway_merchant_id))
            ->assertOk()
            ->assertJsonPath('data.uat.passed', true)
            ->assertJsonPath('data.uat.scenario', 'debit_status_internal_server_error')
            ->assertJsonPath('data.uat.expected.response_code', '5005501')
            ->assertJsonPath('data.uat.received.http_status', 500)
            ->assertJsonPath('data.uat.received.response_code', '5005501')
            ->assertJsonPath('data.uat.received.response_message', 'Internal Server Error')
            ->assertJsonPath('data.uat.request.service_code', 'AZ')
            ->assertJsonMissing(['private_key', 'api_key', 'signature']);

        $this->assertDatabaseHas('dana_merchant_provisioning_audits', [
            'gateway_merchant_id' => $merchant->gateway_merchant_id,
            'operation' => 'dana_sandbox_tester.uat_debit_status_internal_server_error',
            'status' => 'succeeded',
            'safe_details->passed' => true,
            'safe_details->response_code' => '5005501',
            'safe_details->service_code' => 'AZ',
        ]);
    }

    public function test_debit_status_invalid_signature_uat_returns_safe_diagnostics_and_audits(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/uat/debit-status-invalid-signature" => Http::response([
                'data' => [
                    'merchant_code' => $merchant->merchant_code,
                    'environment' => 'sandbox',
                    'uat' => [
                        'scenario' => 'debit_status_invalid_signature',
                        'passed' => true,
                        'expected' => ['response_code' => '4015500', 'response_message' => 'Unauthorized'],
                        'received' => [
                            'http_status' => 401,
                            'response_code' => '4015500',
                            'response_message' => 'Unauthorized. Invalid Signature',
                            'request_id' => 'dana-invalid-sig-001',
                        ],
                        'request' => [
                            'service_code' => '54',
                            'signature_override' => 'invalid_signature',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->actingAdmin();

        $this->postJson($this->debitStatusInvalidSignatureUatUrl($merchant->gateway_merchant_id))
            ->assertOk()
            ->assertJsonPath('data.uat.passed', true)
            ->assertJsonPath('data.uat.scenario', 'debit_status_invalid_signature')
            ->assertJsonPath('data.uat.expected.response_code', '4015500')
            ->assertJsonPath('data.uat.received.http_status', 401)
            ->assertJsonPath('data.uat.received.response_code', '4015500')
            ->assertJsonPath('data.uat.received.response_message', 'Unauthorized. Invalid Signature')
            ->assertJsonPath('data.uat.request.service_code', '54')
            ->assertJsonPath('data.uat.request.signature_override', 'invalid_signature')
            ->assertJsonMissing(['private_key', 'api_key', 'signature']);

        $this->assertDatabaseHas('dana_merchant_provisioning_audits', [
            'gateway_merchant_id' => $merchant->gateway_merchant_id,
            'operation' => 'dana_sandbox_tester.uat_debit_status_invalid_signature',
            'status' => 'succeeded',
            'safe_details->passed' => true,
            'safe_details->response_code' => '4015500',
            'safe_details->signature_override' => 'invalid_signature',
        ]);
    }

    public function test_inconsistent_request_uat_safely_passes_through_failure_diagnostics(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/uat/create-order-inconsistent-request" => Http::response([
                'data' => [
                    'merchant_code' => $merchant->merchant_code,
                    'environment' => 'sandbox',
                    'uat' => [
                        'scenario' => 'create_order_inconsistent_request',
                        'passed' => false,
                        'expected' => ['response_code' => '4045418', 'response_message' => 'Inconsistent Request'],
                        'first_request' => [
                            'amount' => '100000.00',
                            'http_status' => 400,
                            'response_code' => '4005401',
                            'response_message' => 'Invalid Field Format',
                            'request_id' => 'uat-err-req-001',
                        ],
                        'second_request' => [
                            'amount' => '200000.00',
                            'http_status' => 400,
                            'response_code' => '4005401',
                            'response_message' => 'Invalid Field Format',
                            'request_id' => 'uat-err-req-002',
                        ],
                        'received' => [
                            'amount' => '100000.00',
                            'http_status' => 400,
                            'response_code' => '4005401',
                            'response_message' => 'Invalid Field Format',
                            'request_id' => 'uat-err-req-001',
                        ],
                    ],
                ],
            ]),
        ]);

        $this->actingAdmin();
        $this->postJson($this->inconsistentRequestUatUrl($merchant->gateway_merchant_id))
            ->assertOk()
            ->assertJsonPath('data.uat.passed', false)
            ->assertJsonPath('data.uat.first_request.response_code', '4005401')
            ->assertJsonPath('data.uat.received.response_code', '4005401')
            ->assertJsonMissing(['private_key', 'api_key', 'signature']);
    }

    public function test_arm_sandbox_webhook_simulated_500_uat_returns_only_safe_diagnostics(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/uat/arm-webhook-500" => Http::response([
                'data' => [
                    'merchant_code' => $merchant->merchant_code,
                    'environment' => 'sandbox',
                    'uat' => [
                        'scenario' => 'finish_notify_simulated_500',
                        'armed' => true,
                        'payment_id' => '01M5TESTPAYMENT00000000001',
                        'partner_reference_no' => '01M5TESTPAYMENT00000000001',
                        'expected' => [
                            'http_status' => 500,
                            'response_code' => '5005601',
                            'response_message' => 'Internal Server Error',
                        ],
                        'single_use' => true,
                        'expires_in_minutes' => 10,
                        'expires_at' => '2026-10-27T19:50:00+07:00',
                    ],
                ],
            ]),
        ]);

        $this->actingAdmin();
        $this->postJson($this->armWebhook500UatUrl($merchant->gateway_merchant_id), ['payment_id' => '01M5TESTPAYMENT00000000001'])
            ->assertOk()
            ->assertJsonPath('data.uat.scenario', 'finish_notify_simulated_500')
            ->assertJsonPath('data.uat.armed', true)
            ->assertJsonPath('data.uat.expected.http_status', 500)
            ->assertJsonPath('data.uat.expected.response_code', '5005601')
            ->assertJsonMissing(['private_key', 'api_key', 'signature']);
    }

    public function test_creating_transaction_binds_a_hashed_result_context_before_returning_checkout(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        $idempotencyKey = 'frontend-sandbox-tester-action-001';
        $gatewayContext = null;
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/transactions" => function (Request $request) use ($merchant, &$gatewayContext) {
                $gatewayContext = $request->data()['return_context'] ?? null;

                return Http::response(['data' => $this->transaction($merchant->merchant_code, $gatewayContext)], 201);
            },
        ]);
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        $response = $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson($this->transactionUrl($merchant->gateway_merchant_id), ['amount_minor' => 10_000, 'payment_method' => 'dana_wallet'])
            ->assertCreated()
            ->assertJsonPath('data.transaction.status', 'pending')
            ->assertJsonPath('data.transaction.checkout_available', true)
            ->assertJsonPath('data.transaction.checkout_url', 'https://checkout.sandbox.dana.id/session/udev-test')
            ->assertJsonMissingPath('data.transaction.private_key');

        $resultContext = $response->json('data.result_context');
        $this->assertIsString($gatewayContext);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $gatewayContext);
        $this->assertSame($gatewayContext, $resultContext);
        $this->assertDatabaseHas('dana_sandbox_result_contexts', [
            'admin_user_id' => $admin->id,
            'gateway_merchant_id' => $merchant->gateway_merchant_id,
            'gateway_payment_id' => '01K7A2M3N4P5Q6R7S8T9V0W1X2',
            'context_hash' => hash('sha256', $gatewayContext),
        ]);

        Http::assertSent(function (Request $request) use ($idempotencyKey): bool {
            return $request->method() === 'POST'
                && $request->header('Idempotency-Key') === [$idempotencyKey]
                && $request->data()['amount_minor'] === 10_000
                && $request->data()['payment_method'] === 'dana_wallet'
                && is_string($request->data()['return_context'] ?? null)
                && $request->header('Authorization') === ['Bearer '.self::ServiceToken];
        });
        $this->assertDatabaseHas('dana_merchant_provisioning_audits', [
            'operation' => 'dana_sandbox_tester.transaction_created',
            'status' => 'succeeded',
        ]);
    }

    public function test_result_endpoint_requires_the_creating_admin_valid_context_and_matching_merchant_payment(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        $paymentId = '01K7A2M3N4P5Q6R7S8T9V0W1X2';
        $context = str_repeat('A', 43);
        $admin = User::factory()->create(['is_admin' => true]);
        DanaSandboxResultContext::query()->create([
            'admin_user_id' => $admin->id,
            'gateway_merchant_id' => $merchant->gateway_merchant_id,
            'gateway_payment_id' => $paymentId,
            'context_hash' => hash('sha256', $context),
            'idempotency_fingerprint' => hash('sha256', 'result-endpoint-test'),
            'expires_at' => now()->addMinutes(20),
        ]);
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/transactions/{$paymentId}/status" => Http::response([
                'data' => $this->transaction($merchant->merchant_code),
            ]),
        ]);

        $this->getJson("/api/v1/admin/dana-gateway-merchants/sandbox-payment-results/{$context}")
            ->assertUnauthorized();

        Sanctum::actingAs($admin, ['admin:access']);
        $this->getJson("/api/v1/admin/dana-gateway-merchants/sandbox-payment-results/{$context}")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.transaction.id', $paymentId)
            ->assertJsonPath('data.transaction.status', 'pending');

        $this->getJson("/api/v1/admin/dana-gateway-merchants/{$merchant->gateway_merchant_id}/sandbox-tester/transactions/{$paymentId}/result")
            ->assertOk()
            ->assertJsonPath('data.transaction.id', $paymentId);

        Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['admin:access']);
        $this->getJson("/api/v1/admin/dana-gateway-merchants/sandbox-payment-results/{$context}")->assertNotFound();
        $this->getJson("/api/v1/admin/dana-gateway-merchants/{$merchant->gateway_merchant_id}/sandbox-tester/transactions/{$paymentId}/result")->assertForbidden();
    }

    public function test_query_dana_status_is_sandbox_scoped_audited_and_keeps_local_status_separate(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        $paymentId = '01K7A2M3N4P5Q6R7S8T9V0W1X2';
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/transactions/{$paymentId}/dana-status" => Http::response([
                'data' => [
                    'merchant_code' => $merchant->merchant_code,
                    'environment' => 'sandbox',
                    'payment_id' => $paymentId,
                    'local_status' => 'pending',
                    'dana_status' => [
                        'http_status' => 200,
                        'response_code' => '2005500',
                        'latest_transaction_status' => '00',
                        'request_id' => 'dana-query-001',
                        'checked_at' => '2026-10-26T12:00:00+07:00',
                    ],
                ],
            ]),
        ]);
        $this->actingAdmin();

        $this->getJson($this->danaStatusUrl($merchant->gateway_merchant_id, $paymentId))
            ->assertOk()
            ->assertJsonPath('data.local_status', 'pending')
            ->assertJsonPath('data.dana_status.response_code', '2005500')
            ->assertJsonPath('data.dana_status.latest_transaction_status', '00')
            ->assertJsonPath('data.dana_status.request_id', 'dana-query-001')
            ->assertJsonMissingPath('data.private_key');

        $this->assertDatabaseHas('dana_merchant_provisioning_audits', [
            'gateway_merchant_id' => $merchant->gateway_merchant_id,
            'operation' => 'dana_sandbox_tester.dana_payment_status_queried',
            'status' => 'succeeded',
        ]);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), "/sandbox-tester/transactions/{$paymentId}/dana-status")
            && $request->header('Authorization') === ['Bearer '.self::ServiceToken]);
    }

    public function test_query_dana_status_supports_expired_transaction_with_cancelled_debit_status_05_and_audits_safely(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        $paymentId = '01K7A2M3N4P5Q6R7S8T9V0W1X9';
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/transactions/{$paymentId}/dana-status" => Http::response([
                'data' => [
                    'merchant_code' => $merchant->merchant_code,
                    'environment' => 'sandbox',
                    'payment_id' => $paymentId,
                    'local_status' => 'expired',
                    'dana_status' => [
                        'http_status' => 200,
                        'response_code' => '2005500',
                        'latest_transaction_status' => '05',
                        'request_id' => 'dana-query-expired-05',
                        'checked_at' => '2026-10-27T12:00:00+07:00',
                    ],
                ],
            ]),
        ]);
        $this->actingAdmin();

        $this->getJson($this->danaStatusUrl($merchant->gateway_merchant_id, $paymentId))
            ->assertOk()
            ->assertJsonPath('data.local_status', 'expired')
            ->assertJsonPath('data.dana_status.http_status', 200)
            ->assertJsonPath('data.dana_status.response_code', '2005500')
            ->assertJsonPath('data.dana_status.latest_transaction_status', '05')
            ->assertJsonPath('data.dana_status.request_id', 'dana-query-expired-05')
            ->assertJsonMissingPath('data.private_key');

        $this->assertDatabaseHas('dana_merchant_provisioning_audits', [
            'gateway_merchant_id' => $merchant->gateway_merchant_id,
            'operation' => 'dana_sandbox_tester.dana_payment_status_queried',
            'status' => 'succeeded',
            'safe_details->local_status' => 'expired',
            'safe_details->dana_latest_transaction_status' => '05',
        ]);
    }

    public function test_tester_history_is_sanitized_before_it_reaches_the_admin_ui(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/transactions*" => Http::response([
                'data' => [[
                    'id' => 10,
                    'merchant_code' => $merchant->merchant_code,
                    'environment' => 'sandbox',
                    'operation' => 'transaction_created',
                    'status' => 'failed',
                    'message' => 'Checkout Sandbox belum dapat dibuat.',
                    'payment_id' => null,
                    'payment_status' => null,
                    'created_at' => '2026-10-17T10:00:00+07:00',
                    'private_key' => 'must-not-reach-ui',
                ]],
                'meta' => ['current_page' => 1, 'per_page' => 15, 'total' => 1, 'last_page' => 1],
            ]),
        ]);
        $this->actingAdmin();

        $this->getJson($this->transactionUrl($merchant->gateway_merchant_id))
            ->assertOk()
            ->assertJsonPath('data.0.message', 'Checkout Sandbox belum dapat dibuat.')
            ->assertJsonMissingPath('data.0.private_key')
            ->assertDontSee('must-not-reach-ui');
    }

    public function test_tester_history_forwards_the_requested_page_to_the_gateway(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        $requestedPages = [];
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/sandbox-tester/transactions*" => function (Request $request) use ($merchant, &$requestedPages) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $page = (int) ($query['page'] ?? 1);
                $requestedPages[] = $page;

                return Http::response([
                    'data' => [[
                        'id' => $page,
                        'merchant_code' => $merchant->merchant_code,
                        'environment' => 'sandbox',
                        'operation' => 'transaction_created',
                        'status' => 'succeeded',
                        'message' => 'Checkout Sandbox berhasil dibuat.',
                        'payment_id' => null,
                        'payment_status' => null,
                        'created_at' => '2026-10-17T10:00:00+07:00',
                    ]],
                    'meta' => ['current_page' => $page, 'per_page' => 1, 'total' => 3, 'last_page' => 3],
                ]);
            },
        ]);
        $this->actingAdmin();

        foreach ([1, 2, 3] as $page) {
            $this->getJson($this->transactionUrl($merchant->gateway_merchant_id)."?page={$page}&per_page=1")
                ->assertOk()
                ->assertJsonPath('data.0.id', $page)
                ->assertJsonPath('meta.current_page', $page);
        }

        $this->assertSame([1, 2, 3], $requestedPages);
    }

    public function test_payment_history_uses_verified_transaction_status_and_keeps_admin_isolation(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        $paymentId = '01K7A2M3N4P5Q6R7S8T9V0W1X2';
        $admin = User::factory()->create(['is_admin' => true]);
        DanaSandboxResultContext::query()->create([
            'admin_user_id' => $admin->id,
            'gateway_merchant_id' => $merchant->gateway_merchant_id,
            'gateway_payment_id' => $paymentId,
            'context_hash' => hash('sha256', 'payment-history-context'),
            'idempotency_fingerprint' => hash('sha256', 'payment-history-test'),
            'expires_at' => now()->subMinute(),
        ]);
        $webhookPages = [];
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/payment-audit/summary*" => Http::response([
                'data' => ['total' => 1, 'paid' => 1, 'pending' => 0, 'failed_or_expired' => 0, 'paid_amounts' => [['currency' => 'IDR', 'paid_amount_minor' => 10000]]],
            ]),
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/payments/{$paymentId}/webhooks*" => function (Request $request) use (&$webhookPages) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $page = (int) ($query['page'] ?? 1);
                $webhookPages[] = $page;

                return Http::response([
                    'data' => [['received_at' => '2026-10-17T10:01:00+07:00', 'processed_at' => '2026-10-17T10:01:01+07:00', 'signature_valid' => true, 'processing_status' => 'processed', 'error_code' => null, 'provider_event_id' => null]],
                    'meta' => ['current_page' => $page, 'per_page' => 15, 'total' => 2, 'last_page' => 2],
                ]);
            },
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/payment-audit*" => Http::response([
                'data' => [$this->auditPayment($paymentId, 'paid')],
                'meta' => ['current_page' => 1, 'per_page' => 15, 'total' => 1, 'last_page' => 1],
            ]),
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/payments/{$paymentId}/audit" => Http::response([
                'data' => $this->auditPayment($paymentId, 'paid', true),
            ]),
        ]);

        $paymentsUrl = "/api/v1/admin/dana-gateway-merchants/{$merchant->gateway_merchant_id}/sandbox-tester/payments";
        $this->getJson($paymentsUrl)->assertUnauthorized();

        Sanctum::actingAs($admin, ['admin:access']);
        $this->getJson($paymentsUrl)
            ->assertOk()
            ->assertJsonPath('data.0.id', $paymentId)
            ->assertJsonPath('data.0.status', 'paid')
            ->assertJsonMissingPath('data.0.checkout_url');

        $this->getJson("{$paymentsUrl}/{$paymentId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');
        $this->getJson("{$paymentsUrl}/summary")
            ->assertOk()
            ->assertJsonPath('data.paid', 1)
            ->assertJsonPath('data.paid_amounts.0.currency', 'IDR');
        $this->getJson("{$paymentsUrl}/{$paymentId}/webhooks")
            ->assertOk()
            ->assertJsonPath('data.0.signature_valid', true)
            ->assertJsonMissingPath('data.0.payload_sha256');

        $this->getJson("{$paymentsUrl}/{$paymentId}/webhooks?page=2&per_page=25")
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2);

        $this->assertSame([1, 2], $webhookPages);

        Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['admin:access']);
        $this->getJson("{$paymentsUrl}/{$paymentId}")->assertForbidden();
    }

    public function test_sandbox_payment_detail_preserves_checkout_url_and_instruction(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        $paymentId = '01K7A2M3N4P5Q6R7S8T9V0W1X9';
        $admin = User::factory()->create(['is_admin' => true]);
        DanaSandboxResultContext::query()->create([
            'admin_user_id' => $admin->id,
            'gateway_merchant_id' => $merchant->gateway_merchant_id,
            'gateway_payment_id' => $paymentId,
            'context_hash' => hash('sha256', 'detail-checkout-context'),
            'idempotency_fingerprint' => hash('sha256', 'detail-checkout-test'),
            'expires_at' => now()->addMinutes(20),
        ]);
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/payments/{$paymentId}/audit" => Http::response([
                'data' => [
                    'id' => $paymentId,
                    'merchant_reference' => 'UDEV-SANDBOX-CHK',
                    'provider_order_reference' => 'DANA-ORDER-CHK',
                    'amount_minor' => 10_000,
                    'currency' => 'IDR',
                    'status' => 'pending',
                    'payment_method' => 'qris',
                    'created_at' => '2026-10-17T10:00:00+07:00',
                    'updated_at' => '2026-10-17T10:00:00+07:00',
                    'gateway_verified_at' => null,
                    'checkout_url' => 'https://checkout.sandbox.dana.id/session/audit-chk',
                    'checkout_available' => true,
                    'expires_at' => '2026-10-17T10:15:00+07:00',
                    'payment_instruction' => [
                        'type' => 'qris_mpm',
                        'method' => 'qris',
                        'label' => 'QRIS',
                        'message' => 'Pindai QRIS',
                        'qr_content' => '00020101021226570011ID.DANA.WWW011893600915000295707502090029570750303UMI51380014ID.CO.QRIS.WWW02090029570750303UMI5204737253033605405100005802ID5905VZPAY6015Kota Jakarta Se61051224062710119anjartyrtyrtyy1234560440011ID.DANA.WWW0425MER202609041029168370062863046D16',
                    ],
                    'timeline' => [],
                ],
            ]),
        ]);

        Sanctum::actingAs($admin, ['admin:access']);
        $this->getJson("/api/v1/admin/dana-gateway-merchants/{$merchant->gateway_merchant_id}/sandbox-tester/payments/{$paymentId}")
            ->assertOk()
            ->assertJsonPath('data.id', $paymentId)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.checkout_available', true)
            ->assertJsonPath('data.checkout_url', 'https://checkout.sandbox.dana.id/session/audit-chk')
            ->assertJsonPath('data.payment_instruction.type', 'qris_mpm')
            ->assertJsonPath('data.payment_instruction.qr_content', '00020101021226570011ID.DANA.WWW011893600915000295707502090029570750303UMI51380014ID.CO.QRIS.WWW02090029570750303UMI5204737253033605405100005802ID5905VZPAY6015Kota Jakarta Se61051224062710119anjartyrtyrtyy1234560440011ID.DANA.WWW0425MER202609041029168370062863046D16');
    }

    public function test_gateway_audit_retry_after_is_forwarded_safely(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        $paymentId = '01K7A2M3N4P5Q6R7S8T9V0W1X2';
        $admin = User::factory()->create(['is_admin' => true]);
        DanaSandboxResultContext::query()->create([
            'admin_user_id' => $admin->id,
            'gateway_merchant_id' => $merchant->gateway_merchant_id,
            'gateway_payment_id' => $paymentId,
            'context_hash' => hash('sha256', 'audit-retry-after-context'),
            'idempotency_fingerprint' => hash('sha256', 'audit-retry-after-test'),
            'expires_at' => now()->addMinutes(20),
        ]);
        Http::fake([
            "https://dana-gateway.test/api/v1/internal/merchants/{$merchant->gateway_merchant_id}/payments/{$paymentId}/audit" => Http::response([
                'message' => 'upstream throttle detail',
            ], 429, ['Retry-After' => '17']),
        ]);

        Sanctum::actingAs($admin, ['admin:access']);

        $this->getJson("/api/v1/admin/dana-gateway-merchants/{$merchant->gateway_merchant_id}/sandbox-tester/payments/{$paymentId}")
            ->assertStatus(503)
            ->assertHeader('Retry-After', '17')
            ->assertJsonPath('message', 'Gateway provisioning merchant sedang membatasi permintaan. Coba lagi nanti.')
            ->assertDontSee('upstream throttle detail');
    }

    public function test_sandbox_audit_bucket_is_bounded_and_isolated_per_admin(): void
    {
        $merchant = $this->sandboxProvisionedMerchant();
        $url = "/api/v1/admin/dana-gateway-merchants/{$merchant->gateway_merchant_id}/sandbox-tester/payments";
        $this->actingAdmin();

        foreach (range(1, 30) as $attempt) {
            $this->getJson($url)->assertOk();
        }

        $this->getJson($url)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');

        Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['admin:access']);
        $this->getJson($url)->assertOk();
    }

    /** @return array<string, mixed> */
    private function validation(string $merchantCode, string $privateKey): array
    {
        return [
            'merchant_code' => $merchantCode,
            'environment' => 'sandbox',
            'configuration_exists' => true,
            'enabled' => true,
            'required_fields' => [
                'merchant_id' => true,
                'partner_id' => true,
                'origin' => true,
                'channel_id' => true,
                'notification_url' => true,
                'pay_return_url' => true,
                'mcc' => true,
            ],
            'private_key' => ['configured' => true, 'format_valid' => true, 'value' => $privateKey],
            'dana_webhook_public_key' => ['configured' => true, 'format_valid' => true],
            'format_valid' => true,
            'authentication' => [
                'status' => 'not_attempted',
                'message' => 'Autentikasi DANA hanya dapat dikonfirmasi dari respons Create Order Sandbox yang valid.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function transaction(
        string $merchantCode,
        ?string $returnContext = null,
        string $paymentId = '01K7A2M3N4P5Q6R7S8T9V0W1X2',
        string $status = 'pending',
    ): array {
        return array_filter([
            'merchant_code' => $merchantCode,
            'environment' => 'sandbox',
            'authentication' => [
                'status' => 'confirmed_by_checkout_response',
                'message' => 'Create Order Sandbox diterima dengan respons checkout yang valid.',
            ],
            'transaction' => [
                'id' => $paymentId,
                'merchant_order_id' => 'UDEV-SANDBOX-001',
                'amount_minor' => 10_000,
                'currency' => 'IDR',
                'status' => $status,
                'checkout_url' => 'https://checkout.sandbox.dana.id/session/udev-test',
                'checkout_available' => true,
                'expires_at' => '2026-10-17T10:30:00+07:00',
                'payment_instruction' => [
                    'method' => 'dana_wallet',
                    'label' => 'DANA Wallet',
                    'type' => 'redirect',
                    'message' => 'Lanjutkan pembayaran pada halaman DANA Wallet Sandbox.',
                    'payment_code' => null,
                    'qr_image' => null,
                    'qr_url' => null,
                ],
                'created_at' => '2026-10-17T10:00:00+07:00',
                'updated_at' => '2026-10-17T10:00:00+07:00',
            ],
            'return_context' => $returnContext,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    private function auditPayment(string $paymentId, string $status, bool $withTimeline = false): array
    {
        $payment = [
            'id' => $paymentId,
            'merchant_reference' => 'UDEV-SANDBOX-001',
            'provider_order_reference' => 'DANA-ORDER-001',
            'amount_minor' => 10_000,
            'currency' => 'IDR',
            'status' => $status,
            'created_at' => '2026-10-17T10:00:00+07:00',
            'updated_at' => '2026-10-17T10:00:00+07:00',
            'gateway_verified_at' => $status === 'paid' ? '2026-10-17T10:01:00+07:00' : null,
        ];
        if ($withTimeline) {
            $payment['timeline'] = [];
        }

        return $payment;
    }

    private function sandboxProvisionedMerchant(array $overrides = []): DanaMerchantProvisioningAudit
    {
        return DanaMerchantProvisioningAudit::factory()->create([
            'operation' => 'dana_merchant.provision',
            'gateway_merchant_id' => 101,
            'merchant_code' => 'udev-salon',
            'environment' => 'sandbox',
            'status' => 'succeeded',
            ...$overrides,
        ]);
    }

    private function validationUrl(int $merchantId): string
    {
        return "/api/v1/admin/dana-gateway-merchants/{$merchantId}/sandbox-tester/validation";
    }

    private function missingTimestampUatUrl(int $merchantId): string
    {
        return "/api/v1/admin/dana-gateway-merchants/{$merchantId}/sandbox-tester/uat/create-order-missing-timestamp";
    }

    private function invalidAmountFormatUatUrl(int $merchantId): string
    {
        return "/api/v1/admin/dana-gateway-merchants/{$merchantId}/sandbox-tester/uat/create-order-invalid-amount-format";
    }

    private function inconsistentRequestUatUrl(int $merchantId): string
    {
        return "/api/v1/admin/dana-gateway-merchants/{$merchantId}/sandbox-tester/uat/create-order-inconsistent-request";
    }

    private function ipgCreateOrderSuccessUatUrl(int $merchantId): string
    {
        return "/api/v1/admin/dana-gateway-merchants/{$merchantId}/sandbox-tester/uat/ipg-create-order-success";
    }

    private function ipgMissingTimestampUatUrl(int $merchantId): string
    {
        return "/api/v1/admin/dana-gateway-merchants/{$merchantId}/sandbox-tester/uat/ipg-create-order-missing-timestamp";
    }

    private function ipgGeneralErrorUatUrl(int $merchantId): string
    {
        return "/api/v1/admin/dana-gateway-merchants/{$merchantId}/sandbox-tester/uat/ipg-create-order-general-error";
    }

    private function ipgTransactionNotPermittedUatUrl(int $merchantId): string
    {
        return "/api/v1/admin/dana-gateway-merchants/{$merchantId}/sandbox-tester/uat/ipg-create-order-transaction-not-permitted";
    }

    private function ipgInvalidMerchantUatUrl(int $merchantId): string
    {
        return "/api/v1/admin/dana-gateway-merchants/{$merchantId}/sandbox-tester/uat/ipg-create-order-invalid-merchant";
    }

    private function ipgInconsistentRequestUatUrl(int $merchantId): string
    {
        return "/api/v1/admin/dana-gateway-merchants/{$merchantId}/sandbox-tester/uat/ipg-create-order-inconsistent-request";
    }

    private function ipgInvalidSignatureUatUrl(int $merchantId): string
    {
        return "/api/v1/admin/dana-gateway-merchants/{$merchantId}/sandbox-tester/uat/ipg-create-order-invalid-signature";
    }

    private function ipgExceedsAmountLimitUatUrl(int $merchantId): string
    {
        return "/api/v1/admin/dana-gateway-merchants/{$merchantId}/sandbox-tester/uat/ipg-create-order-exceeds-amount-limit";
    }

    private function debitStatusNotFoundUatUrl(int $merchantId): string
    {
        return "/api/v1/admin/dana-gateway-merchants/{$merchantId}/sandbox-tester/uat/debit-status-transaction-not-found";
    }

    private function debitStatusMissingTimestampUatUrl(int $merchantId): string
    {
        return "/api/v1/admin/dana-gateway-merchants/{$merchantId}/sandbox-tester/uat/debit-status-missing-timestamp";
    }

    private function debitStatusInternalServerErrorUatUrl(int $merchantId): string
    {
        return "/api/v1/admin/dana-gateway-merchants/{$merchantId}/sandbox-tester/uat/debit-status-internal-server-error";
    }

    private function debitStatusInvalidSignatureUatUrl(int $merchantId): string
    {
        return "/api/v1/admin/dana-gateway-merchants/{$merchantId}/sandbox-tester/uat/debit-status-invalid-signature";
    }

    private function armWebhook500UatUrl(int $merchantId): string
    {
        return "/api/v1/admin/dana-gateway-merchants/{$merchantId}/sandbox-tester/uat/arm-webhook-500";
    }

    private function transactionUrl(int $merchantId): string
    {
        return "/api/v1/admin/dana-gateway-merchants/{$merchantId}/sandbox-tester/transactions";
    }

    private function danaStatusUrl(int $merchantId, string $paymentId): string
    {
        return $this->transactionUrl($merchantId)."/{$paymentId}/dana-status";
    }

    private function actingAdmin(): self
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['admin:access']);

        return $this;
    }
}
