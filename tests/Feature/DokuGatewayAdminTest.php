<?php

namespace Tests\Feature;

use App\Models\DokuGatewayAudit;
use App\Models\DokuMerchantCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DokuGatewayAdminTest extends TestCase
{
    use RefreshDatabase;

    public const string TestPrivateKey = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvwIBADANBgkqhkiG9w0BAQEFAASCBKkwggSlAgEAAoIBAQC2kCNbkVCDeEUx
WAbaakOqh9CvGoJjIDh0V6HMYBwdIUYEgWRZArbj7tb8GAICjeRzFf7KzCvhrNCn
9YVWMDot4IqtSCvbRhYRP7nvlPrHysp0NxVQVfoqysfUIPlduGZ49jyOUt5YGBO3
2XZ8MLjtbl5KnmZuuVvYPb+U+Sz1LA0NapIK8VPpxi7EgvZUOhDRKxzmZwsBQckX
s86Hz94rq9O0dY3xrp8A9NUAeFmhThB26tqBY0w9e/2xvDKcbslNqWowcCD5XQJx
bkqpWy/CKJd768U196OwMoR3ZPVLr3LO+VUis+E5yCcaKYfHRBmU/1ZiUT7Nju+f
D7HdUwoFAgMBAAECggEANyAM3SBizF/rnLu0zgzaQ8rg047QojffdBnl7D11H8Kl
TsT3VP1B1k+PMO3a5YSM38dbeUbvVSSGmMDFM0I6vxGXLj2lqH9gOHMipNo6Y0uz
mCXt1KUxdexnLIGMaqU8o/fOTzdslfMnORN7+IIJxP/XjvaHfZWQyxnSGJyo25qf
562nfkbMwfpPTXLPlWEQc/p0fuVV47Y55nmrCGgh5kUA3Fk0XIgGzZXHJR6K3y7r
KjG4mw25vK6vfUncom9wRSgvEcif3LUF9Lnqn5Cz+owwPVyLc3ejvj6QDqHQuDk2
yfhsi1bz4duwj+jPMvSyO5Uz8w8qd0AXfC89enxFXQKBgQDhOfLEYqHbxcrol7YA
cCIhAe19jKPF8qP8qDe+sHBify4AJ4kbVx++NfziJopWVLXsWg2+iDrRUYmTUbQc
mlLe6drMggonJNlaneCfMmWKg1iDs2HRf+BYib2MNSv6qe2TxNuCXQx2zxmu/7j7
274bZRX2RNaBn2jlhvjosnfYSwKBgQDPgeVwE2uyPby4/UiRcx+wGMkpgfi2j6gC
rpgnQV9S6y7VpEKbvsMmbAh8JcEmN+K9wgMlJe1PuLM39kV1JvbRTF+Np0Lo4QnM
XLvFWCdQhWeuwVlW0UhEskiKv7VEp2YCVz20gPe/lHwdbHpdK61AsKMLvXNR0KZB
nSg90bbU7wKBgQCWUzUjuqlJpKSfxdKmTDGbUkwPBjWxQxgM5MIANodsVnJ/i+PJ
Kcd4D1SwJQUkRfn+vMR7rTWEzCVE/L14cdBqFDADW7AnHEMuZj2sQ9jeMHh9vtbF
G9LDhXvL/xlRfuWhQT05r51IF0aPNQNkpvAmHLwh2SRK8/jxabrspWG0LQKBgQCh
OETwjMYx17GW8IK/LvjBsqAoNajs/jQE0W25ohrTq5HZvyNZKh8o7OqyugqVDPqH
gAM9i3gTZmcn5ZkVak59Up8yyW88zAr20l2498Kj1uI1bldhEnzZksK1uji1vHGP
BAUXBtR17oF3wt5XISYciqBC8NB2Emv5soafcR5h1QKBgQDSj8IqhGd5CpjXi9Mj
XIOld99s/KBHqF5qqla6WJnCFClTtS0gVFKzLxeFGxirgRr//rGDTdf4RaMRsyrx
gTAgBx+VEu0FxbJApxQyZW4PS7uIB+53YOJVsnFChX8th2ffeP88d3eFMPqRnjji
bjULk3DAiQoIToQvGEBqworpcg==
-----END PRIVATE KEY-----
PEM;

    private User $admin;
    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'is_admin' => true,
        ]);

        $this->regularUser = User::factory()->create([
            'is_admin' => false,
        ]);
    }

    public function test_unauthenticated_user_cannot_access_doku_gateway(): void
    {
        $this->getJson('/api/v1/admin/doku-gateway/merchants')->assertStatus(401);
        $this->getJson('/api/v1/admin/doku-gateway/configuration')->assertStatus(401);
        $this->putJson('/api/v1/admin/doku-gateway/configuration', [])->assertStatus(401);
        $this->postJson('/api/v1/admin/doku-gateway/test-token', [])->assertStatus(401);
        $this->getJson('/api/v1/admin/doku-gateway/audits')->assertStatus(401);
    }

    public function test_non_admin_cannot_access_doku_gateway(): void
    {
        Sanctum::actingAs($this->regularUser, ['admin:access']);

        $this->getJson('/api/v1/admin/doku-gateway/merchants')->assertStatus(403);
        $this->getJson('/api/v1/admin/doku-gateway/configuration')->assertStatus(403);
    }

    public function test_admin_can_get_doku_gateway_merchants_successfully(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $response = $this->getJson('/api/v1/admin/doku-gateway/merchants');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ])
            ->assertJsonStructure([
                'status',
                'data' => [
                    '*' => [
                        'merchant_code',
                        'merchant_name',
                        'environment',
                        'is_configured',
                        'is_active',
                    ],
                ],
            ]);

        $data = $response->json('data');
        $codes = array_column($data, 'merchant_code');
        $this->assertContains('udev-central', $codes);
        $this->assertContains('larissama', $codes);
    }

    public function test_doku_gateway_merchants_endpoint_accessible_under_route_cache(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        \Illuminate\Support\Facades\Artisan::call('route:cache');

        try {
            $response = $this->getJson('/api/v1/admin/doku-gateway/merchants');
            $response->assertStatus(200)
                ->assertJson(['status' => 'success']);

            $showResponse = $this->getJson('/api/v1/admin/doku-gateway/configuration?merchant_code=udev-central');
            $showResponse->assertStatus(200)
                ->assertJson(['status' => 'success']);
        } finally {
            \Illuminate\Support\Facades\Artisan::call('route:clear');
        }
    }

    public function test_admin_can_save_and_retrieve_credentials_with_encrypted_private_key_and_masking(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $response = $this->putJson('/api/v1/admin/doku-gateway/configuration', [
            'merchant_code' => 'udev-central',
            'merchant_name' => 'UDEV Central Billing',
            'client_id' => 'MALL-ID-88889999',
            'private_key' => self::TestPrivateKey,
            'environment' => 'sandbox',
            'is_active' => true,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'merchant_code' => 'udev-central',
                    'merchant_name' => 'UDEV Central Billing',
                    'masked_client_id' => 'MALL****9999',
                    'environment' => 'sandbox',
                    'has_private_key' => true,
                ],
            ]);

        // Verifikasi private key TIDAK dikembalikan di respons
        $this->assertArrayNotHasKey('private_key', $response->json('data'));
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', json_encode($response->json()));

        // Verifikasi database menyimpan encrypted private key
        $cred = DokuMerchantCredential::where('merchant_code', 'udev-central')->firstOrFail();
        $this->assertNotEmpty($cred->encrypted_private_key);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $cred->encrypted_private_key);
        $this->assertNotEmpty($cred->key_nonce);
        $this->assertNotEmpty($cred->key_tag);
        $this->assertEquals('v1', $cred->key_version);

        // Verifikasi endpoint show configuration
        $showResponse = $this->getJson('/api/v1/admin/doku-gateway/configuration?merchant_code=udev-central');
        $showResponse->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'merchant_code' => 'udev-central',
                    'masked_client_id' => 'MALL****9999',
                    'has_private_key' => true,
                    'is_configured' => true,
                ],
            ]);
        $this->assertArrayNotHasKey('private_key', $showResponse->json('data'));
    }

    public function test_saving_production_environment_is_rejected(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $response = $this->putJson('/api/v1/admin/doku-gateway/configuration', [
            'merchant_code' => 'udev-central',
            'merchant_name' => 'UDEV Central Billing',
            'client_id' => 'MALL-123',
            'private_key' => self::TestPrivateKey,
            'environment' => 'production', // Dilarang!
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['environment']);
    }

    public function test_admin_can_test_snap_b2b_token_with_caching_buffer(): void
    {
        Cache::flush();
        Sanctum::actingAs($this->admin, ['admin:access']);

        // Simpan credential terlebih dahulu
        $this->putJson('/api/v1/admin/doku-gateway/configuration', [
            'merchant_code' => 'udev-central',
            'merchant_name' => 'UDEV Central Billing',
            'client_id' => 'MALL-TEST-5555',
            'private_key' => self::TestPrivateKey,
            'environment' => 'sandbox',
            'is_active' => true,
        ])->assertStatus(200);

        Http::fake([
            '*/api/v1/internal/doku/test-b2b-token' => Http::sequence()
                ->push([
                    'status' => 'success',
                    'data' => [
                        'response_code' => '2007300',
                        'response_message' => 'Successful',
                        'token_type' => 'Bearer',
                        'expires_in' => 900,
                        'buffer_seconds' => 60,
                        'cache_ttl_seconds' => 840,
                        'is_cached' => false,
                        'latency_ms' => 15,
                        'merchant_code' => 'udev-central',
                        'environment' => 'sandbox',
                        'target_endpoint' => 'https://api-sandbox.doku.com/authorization/v1/access-token/b2b',
                        'masked_token' => 'eyJhbGci...********...234567',
                    ],
                ], 200)
                ->push([
                    'status' => 'success',
                    'data' => [
                        'response_code' => '2007300',
                        'response_message' => 'Successful',
                        'token_type' => 'Bearer',
                        'expires_in' => 900,
                        'buffer_seconds' => 60,
                        'cache_ttl_seconds' => 840,
                        'is_cached' => true,
                        'latency_ms' => 0,
                        'merchant_code' => 'udev-central',
                        'environment' => 'sandbox',
                        'target_endpoint' => 'https://api-sandbox.doku.com/authorization/v1/access-token/b2b',
                        'masked_token' => 'eyJhbGci...********...234567',
                    ],
                ], 200)
                ->push([
                    'status' => 'success',
                    'data' => [
                        'response_code' => '2007300',
                        'response_message' => 'Successful',
                        'token_type' => 'Bearer',
                        'expires_in' => 900,
                        'buffer_seconds' => 60,
                        'cache_ttl_seconds' => 840,
                        'is_cached' => false,
                        'latency_ms' => 18,
                        'merchant_code' => 'udev-central',
                        'environment' => 'sandbox',
                        'target_endpoint' => 'https://api-sandbox.doku.com/authorization/v1/access-token/b2b',
                        'masked_token' => 'eyJhbGci...********...234567',
                    ],
                ], 200),
        ]);

        // Request 1: Fresh token through doku-payment gateway
        $testResponse1 = $this->postJson('/api/v1/admin/doku-gateway/test-token', [
            'merchant_code' => 'udev-central',
            'force_refresh' => false,
        ]);

        $testResponse1->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'response_code' => '2007300',
                    'response_message' => 'Successful',
                    'token_type' => 'Bearer',
                    'expires_in' => 900,
                    'buffer_seconds' => 60,
                    'cache_ttl_seconds' => 840,
                    'is_cached' => false,
                    'target_endpoint' => 'https://api-sandbox.doku.com/authorization/v1/access-token/b2b',
                ],
            ]);

        $this->assertNotEmpty($testResponse1->json('data.masked_token'));
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', json_encode($testResponse1->json()));
        $this->assertArrayNotHasKey('private_key', $testResponse1->json('data'));

        // Request 2: Cached token returned from gateway
        $testResponse2 = $this->postJson('/api/v1/admin/doku-gateway/test-token', [
            'merchant_code' => 'udev-central',
            'force_refresh' => false,
        ]);

        $testResponse2->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'response_code' => '2007300',
                    'is_cached' => true,
                ],
            ]);

        // Request 3: Force refresh (bypass gateway cache)
        $testResponse3 = $this->postJson('/api/v1/admin/doku-gateway/test-token', [
            'merchant_code' => 'udev-central',
            'force_refresh' => true,
        ]);

        $testResponse3->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'response_code' => '2007300',
                    'is_cached' => false,
                ],
            ]);

        // Verifikasi panggilan service-to-service aman ke doku-payment
        Http::assertSentCount(3);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/v1/internal/doku/test-b2b-token')
                && $request->hasHeader('Authorization', 'Bearer doku_service_secret_token_sandbox_64chars_key')
                && $request['environment'] === 'sandbox'
                && $request['merchant_code'] === 'udev-central'
                && ! empty($request['private_key']);
        });
    }

    public function test_audit_logs_record_actions_without_secret_data(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $this->putJson('/api/v1/admin/doku-gateway/configuration', [
            'merchant_code' => 'udev-central',
            'merchant_name' => 'UDEV Central Billing',
            'client_id' => 'MALL-AUDIT-TEST',
            'private_key' => self::TestPrivateKey,
            'environment' => 'sandbox',
            'is_active' => true,
        ])->assertStatus(200);

        $audits = DokuGatewayAudit::where('merchant_code', 'udev-central')->get();
        $this->assertNotEmpty($audits);

        foreach ($audits as $audit) {
            $metadataString = json_encode($audit->metadata);
            $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $metadataString);
            $this->assertStringNotContainsString(self::TestPrivateKey, $metadataString);
        }

        // Test GET audits endpoint
        $response = $this->getJson('/api/v1/admin/doku-gateway/audits?merchant_code=udev-central');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data',
                'meta' => ['current_page', 'last_page', 'total'],
            ]);
    }

    public function test_token_test_handles_gateway_failure_gracefully(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $this->putJson('/api/v1/admin/doku-gateway/configuration', [
            'merchant_code' => 'udev-central',
            'merchant_name' => 'UDEV Central Billing',
            'client_id' => 'MALL-TEST-FAIL',
            'private_key' => self::TestPrivateKey,
            'environment' => 'sandbox',
            'is_active' => true,
        ])->assertStatus(200);

        Http::fake([
            '*/api/v1/internal/doku/test-b2b-token' => Http::response([
                'status' => 'failed',
                'data' => [
                    'response_code' => '4017300',
                    'response_message' => 'Unauthorized. Invalid Signature.',
                    'token_type' => '',
                    'expires_in' => 0,
                    'buffer_seconds' => 60,
                    'cache_ttl_seconds' => 0,
                    'is_cached' => false,
                    'latency_ms' => 20,
                    'merchant_code' => 'udev-central',
                    'environment' => 'sandbox',
                    'masked_token' => '',
                ],
            ], 400),
        ]);

        $response = $this->postJson('/api/v1/admin/doku-gateway/test-token', [
            'merchant_code' => 'udev-central',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'status' => 'failed',
                'data' => [
                    'response_code' => '4017300',
                    'response_message' => 'Unauthorized. Invalid Signature.',
                ],
            ]);

        // Verifikasi status tersimpan di credential
        $cred = DokuMerchantCredential::where('merchant_code', 'udev-central')->firstOrFail();
        $this->assertEquals('failed', $cred->last_token_test_status);
        $this->assertEquals('4017300', $cred->last_token_test_response_code);
    }

    public function test_repeated_migrations_are_non_destructive(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $this->putJson('/api/v1/admin/doku-gateway/configuration', [
            'merchant_code' => 'udev-central',
            'merchant_name' => 'UDEV Central Billing',
            'client_id' => 'MALL-MIGRATE-SAFE',
            'private_key' => self::TestPrivateKey,
            'environment' => 'sandbox',
            'is_active' => true,
        ])->assertStatus(200);

        // Menjalankan artisan migrate --force lagi tidak boleh error atau menghapus data
        $exitCode = \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
        $this->assertSame(0, $exitCode);

        // Verifikasi data tetap utuh
        $cred = DokuMerchantCredential::where('merchant_code', 'udev-central')->first();
        $this->assertNotNull($cred);
        $this->assertSame('MALL-MIGRATE-SAFE', $cred->client_id);
    }
}
