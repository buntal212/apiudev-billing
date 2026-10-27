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
