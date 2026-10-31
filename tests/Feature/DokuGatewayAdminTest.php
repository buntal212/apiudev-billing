<?php

namespace Tests\Feature;

use App\Models\DokuGatewayAudit;
use App\Models\DokuMerchantCredential;
use App\Models\DokuMerchantKeyBackup;
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
        $this->deleteJson('/api/v1/admin/doku-gateway/configuration', [])->assertStatus(401);
        $this->postJson('/api/v1/admin/doku-gateway/test-token', [])->assertStatus(401);
        $this->getJson('/api/v1/admin/doku-gateway/audits')->assertStatus(401);
    }

    public function test_non_admin_cannot_access_doku_gateway(): void
    {
        Sanctum::actingAs($this->regularUser, ['admin:access']);

        $this->getJson('/api/v1/admin/doku-gateway/merchants')->assertStatus(403);
        $this->getJson('/api/v1/admin/doku-gateway/configuration')->assertStatus(403);
        $this->deleteJson('/api/v1/admin/doku-gateway/configuration', [])->assertStatus(403);
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

        // 1. Verifikasi endpoint dapat diakses secara normal
        $response = $this->getJson('/api/v1/admin/doku-gateway/merchants');
        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);

        // 2. Verifikasi rute terdaftar dengan URI dan action yang tepat
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByAction('App\Http\Controllers\Api\V1\Admin\DokuGatewayAdminController@indexMerchants');
        $this->assertNotNull($route);
        $this->assertEquals('api/v1/admin/doku-gateway/merchants', $route->uri());

        // 3. Verifikasi bahwa kompilasi route:cache sukses tanpa kegagalan serialisasi
        $exitCode = \Illuminate\Support\Facades\Artisan::call('route:cache');
        $this->assertSame(0, $exitCode);
        \Illuminate\Support\Facades\Artisan::call('route:clear');
    }

    public function test_admin_can_generate_rsa_key_pair(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $response = $this->postJson('/api/v1/admin/doku-gateway/generate-key', [
            'merchant_code' => 'udev-central',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'merchant_code' => 'udev-central',
                    'is_configured' => true,
                    'has_private_key' => true,
                    'is_rotated' => false,
                ],
            ]);

        $publicKey = $response->json('data.public_key');
        $fingerprint = $response->json('data.public_key_fingerprint');

        $this->assertStringContainsString('-----BEGIN PUBLIC KEY-----', $publicKey);
        $this->assertEquals(64, strlen($fingerprint));

        // Pastikan TIDAK pernah mengekspos private key
        $this->assertArrayNotHasKey('private_key', $response->json('data'));
        $this->assertStringNotContainsString('BEGIN RSA PRIVATE KEY', json_encode($response->json()));
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', json_encode($response->json()));

        // Verifikasi database menyimpan encrypted private key yang valid
        $cred = DokuMerchantCredential::where('merchant_code', 'udev-central')->firstOrFail();
        $this->assertNotEmpty($cred->encrypted_private_key);
        $this->assertStringNotContainsString('BEGIN', $cred->encrypted_private_key);
        $this->assertEquals($publicKey, $cred->public_key);
        $this->assertEquals($fingerprint, $cred->public_key_fingerprint);

        // Verifikasi audit log mencatat aksi tanpa membocorkan private key
        $audit = DokuGatewayAudit::where('merchant_code', 'udev-central')
            ->where('action', 'key.generated')
            ->firstOrFail();

        $this->assertEquals('success', $audit->status);
        $this->assertStringNotContainsString('BEGIN', json_encode($audit->metadata));
        $this->assertEquals($fingerprint, $audit->metadata['public_key_fingerprint']);
    }

    public function test_generating_key_when_key_exists_requires_confirmation(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        // Generate key pertama kali
        $this->postJson('/api/v1/admin/doku-gateway/generate-key', [
            'merchant_code' => 'udev-central',
        ])->assertStatus(200);

        $initialCred = DokuMerchantCredential::where('merchant_code', 'udev-central')->firstOrFail();

        // Coba generate lagi tanpa konfirmasi rotasi -> Wajib ditolak dengan 409
        $response = $this->postJson('/api/v1/admin/doku-gateway/generate-key', [
            'merchant_code' => 'udev-central',
            'confirm_rotation' => false,
        ]);

        $response->assertStatus(409)
            ->assertJson([
                'status' => 'confirmation_required',
                'data' => [
                    'merchant_code' => 'udev-central',
                    'requires_confirmation' => true,
                ],
            ]);

        // Pastikan key lama tidak berubah
        $freshCred = DokuMerchantCredential::where('merchant_code', 'udev-central')->firstOrFail();
        $this->assertEquals($initialCred->encrypted_private_key, $freshCred->encrypted_private_key);
    }

    public function test_admin_can_rotate_rsa_key_pair_and_recover_previous_key(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        // 1. Generate key pertama
        $res1 = $this->postJson('/api/v1/admin/doku-gateway/generate-key', [
            'merchant_code' => 'udev-central',
        ]);
        $res1->assertStatus(200);
        $firstPublicKey = $res1->json('data.public_key');
        $firstFingerprint = $res1->json('data.public_key_fingerprint');

        // 2. Rotasi key dengan konfirmasi
        $res2 = $this->postJson('/api/v1/admin/doku-gateway/generate-key', [
            'merchant_code' => 'udev-central',
            'confirm_rotation' => true,
        ]);
        $res2->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'is_rotated' => true,
                    'has_recovery_backup' => true,
                ],
            ]);

        $secondPublicKey = $res2->json('data.public_key');
        $secondFingerprint = $res2->json('data.public_key_fingerprint');

        $this->assertNotEquals($firstFingerprint, $secondFingerprint);

        // Verifikasi tabel backup memiliki key pertama
        $backup = DokuMerchantKeyBackup::where('merchant_code', 'udev-central')->firstOrFail();
        $this->assertEquals($firstFingerprint, $backup->public_key_fingerprint);
        $this->assertEquals($firstPublicKey, $backup->public_key);

        // Verifikasi audit log mencatat aksi key.rotated
        $this->assertTrue(DokuGatewayAudit::where('merchant_code', 'udev-central')->where('action', 'key.rotated')->exists());

        // 3. Pulihkan key lama (Rollback / Recover)
        $recoverRes = $this->postJson('/api/v1/admin/doku-gateway/recover-key', [
            'merchant_code' => 'udev-central',
        ]);

        $recoverRes->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'public_key_fingerprint' => $firstFingerprint,
                ],
            ]);

        // Verifikasi database mengembalikan key pertama
        $recoveredCred = DokuMerchantCredential::where('merchant_code', 'udev-central')->firstOrFail();
        $this->assertEquals($firstFingerprint, $recoveredCred->public_key_fingerprint);
        $this->assertEquals($firstPublicKey, $recoveredCred->public_key);

        // Verifikasi audit mencatat key.recovered
        $this->assertTrue(DokuGatewayAudit::where('merchant_code', 'udev-central')->where('action', 'key.recovered')->exists());
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

    public function test_admin_can_delete_configuration_and_maintains_recovery_backup_and_audits(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        // 1. Setup konfigurasi aktif
        $this->putJson('/api/v1/admin/doku-gateway/configuration', [
            'merchant_code' => 'udev-central',
            'merchant_name' => 'UDEV Central Billing',
            'client_id' => 'DEV-UDEV-DELETE-TEST',
            'private_key' => self::TestPrivateKey,
            'environment' => 'sandbox',
            'is_active' => true,
        ])->assertStatus(200);

        // 2. Setup token cache
        Cache::put('doku_snap_b2b_token:udev-central:sandbox', 'cached-token-12345', 300);
        $this->assertTrue(Cache::has('doku_snap_b2b_token:udev-central:sandbox'));

        // 3. Setup backup RSA
        $backup = DokuMerchantKeyBackup::create([
            'merchant_code' => 'udev-central',
            'environment' => 'sandbox',
            'encrypted_private_key' => 'dummy-encrypted-data',
            'key_nonce' => 'nonce123',
            'key_tag' => 'tag123',
            'key_version' => 'v1',
            'public_key' => '-----BEGIN PUBLIC KEY-----...',
            'public_key_fingerprint' => 'backup-fingerprint-abc',
            'rotated_at' => now(),
            'admin_user_id' => $this->admin->id,
            'reason' => 'test_backup',
        ]);

        // 4. Pastikan ada invoice untuk memverifikasi invoice tidak ikut terhapus
        $invoice = \App\Models\Invoice::factory()->create();

        // 5. Eksekusi Hapus Konfigurasi
        $response = $this->deleteJson('/api/v1/admin/doku-gateway/configuration', [
            'merchant_code' => 'udev-central',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'merchant_code' => 'udev-central',
                    'is_configured' => false,
                    'has_private_key' => false,
                    'has_recovery_backup' => true,
                ],
            ]);

        // 6. Verifikasi kredensial aktif telah di-reset (null) tanpa menghapus baris merchant
        $credAfterDelete = DokuMerchantCredential::where('merchant_code', 'udev-central')->first();
        $this->assertNotNull($credAfterDelete);
        $this->assertNull($credAfterDelete->client_id);
        $this->assertNull($credAfterDelete->encrypted_private_key);

        // 7. Verifikasi cache token dibersihkan
        $this->assertFalse(Cache::has('doku_snap_b2b_token:udev-central:sandbox'));

        // 8. Verifikasi backup RSA TIDAK terhapus
        $this->assertDatabaseHas('doku_merchant_key_backups', [
            'id' => $backup->id,
            'merchant_code' => 'udev-central',
        ]);

        // 9. Verifikasi invoice TIDAK terhapus
        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
        ]);

        // 10. Verifikasi audit log tercatat tanpa secret
        $audit = DokuGatewayAudit::where('merchant_code', 'udev-central')
            ->where('action', 'configuration.deleted')
            ->latest()
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('success', $audit->status);
        $this->assertSame('200', $audit->response_code);
        $this->assertArrayNotHasKey('private_key', $audit->metadata);
        $this->assertArrayNotHasKey('client_id', $audit->metadata);
        $this->assertArrayHasKey('client_id_masked', $audit->metadata);

        // 11. Verifikasi showConfiguration mengembalikan is_configured: false dan has_recovery_backup: true
        $showResponse = $this->getJson('/api/v1/admin/doku-gateway/configuration?merchant_code=udev-central');
        $showResponse->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'merchant_code' => 'udev-central',
                    'is_configured' => false,
                    'has_private_key' => false,
                    'has_recovery_backup' => true,
                ],
            ]);
    }

    public function test_admin_can_recover_key_after_configuration_is_deleted(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        // 1. Simpan konfigurasi awal dan buat backup
        $this->putJson('/api/v1/admin/doku-gateway/configuration', [
            'merchant_code' => 'udev-central',
            'merchant_name' => 'UDEV Central Billing',
            'client_id' => 'DEV-RECOVER-AFTER-DEL',
            'private_key' => self::TestPrivateKey,
            'environment' => 'sandbox',
            'is_active' => true,
        ])->assertStatus(200);

        // Rotasi untuk membuat backup
        $this->postJson('/api/v1/admin/doku-gateway/generate-key', [
            'merchant_code' => 'udev-central',
            'confirm_rotation' => true,
        ])->assertStatus(200);

        $this->assertTrue(DokuMerchantKeyBackup::where('merchant_code', 'udev-central')->exists());

        // 2. Hapus konfigurasi aktif
        $this->deleteJson('/api/v1/admin/doku-gateway/configuration', [
            'merchant_code' => 'udev-central',
        ])->assertStatus(200);

        $credAfterDel = DokuMerchantCredential::where('merchant_code', 'udev-central')->first();
        $this->assertNotNull($credAfterDel);
        $this->assertNull($credAfterDel->client_id);
        $this->assertNull($credAfterDel->encrypted_private_key);

        // 3. Pulihkan kunci dari backup
        $recoverResponse = $this->postJson('/api/v1/admin/doku-gateway/recover-key', [
            'merchant_code' => 'udev-central',
        ]);

        $recoverResponse->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'merchant_code' => 'udev-central',
                    'is_configured' => true,
                    'has_private_key' => true,
                ],
            ]);

        // 4. Kredensial aktif sekarang sudah pulih di database
        $this->assertDatabaseHas('doku_merchant_credentials', [
            'merchant_code' => 'udev-central',
            'environment' => 'sandbox',
        ]);
    }

    public function test_delete_configuration_returns_404_when_merchant_not_configured(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $response = $this->deleteJson('/api/v1/admin/doku-gateway/configuration', [
            'merchant_code' => 'non-existent-merchant',
        ]);

        $response->assertStatus(404)
            ->assertJson([
                'status' => 'failed',
                'data' => [
                    'merchant_code' => 'non-existent-merchant',
                    'is_configured' => false,
                    'has_private_key' => false,
                ],
            ]);
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

    public function test_verify_doku_production_endpoint_command_success(): void
    {
        Http::fake([
            'https://apiudev.udumbara.my.id/api/v1/admin/doku-gateway/merchants' => Http::response([
                'status' => 'success',
                'data' => [
                    ['merchant_code' => 'udev-central'],
                ],
            ], 200),
            'https://apiudev.udumbara.my.id/api/v1/admin/doku-gateway/configuration*' => Http::response([
                'status' => 'failed',
                'message' => 'Konfigurasi tidak ditemukan.',
            ], 404),
        ]);

        $exitCode = \Illuminate\Support\Facades\Artisan::call('doku:verify-endpoint', [
            '--url' => 'https://apiudev.udumbara.my.id/api/v1/admin/doku-gateway/merchants',
        ]);

        $this->assertSame(0, $exitCode);

        // Verifikasi ephemeral token telah dihapus
        $this->assertDatabaseMissing('personal_access_tokens', [
            'name' => 'ci-verify-doku-endpoint',
        ]);
    }

    public function test_verify_doku_production_endpoint_command_fails_on_404(): void
    {
        Http::fake([
            'https://apiudev.udumbara.my.id/api/v1/admin/doku-gateway/merchants' => Http::response([
                'message' => 'Data yang Anda cari tidak ditemukan.',
            ], 404),
        ]);

        $exitCode = \Illuminate\Support\Facades\Artisan::call('doku:verify-endpoint', [
            '--url' => 'https://apiudev.udumbara.my.id/api/v1/admin/doku-gateway/merchants',
        ]);

        $this->assertSame(1, $exitCode);

        // Verifikasi ephemeral token tetap dibersihkan
        $this->assertDatabaseMissing('personal_access_tokens', [
            'name' => 'ci-verify-doku-endpoint',
        ]);
    }

    public function test_verify_doku_production_endpoint_command_fails_on_auth_failure(): void
    {
        Http::fake([
            'https://apiudev.udumbara.my.id/api/v1/admin/doku-gateway/merchants' => Http::response([
                'message' => 'Sesi Anda telah berakhir. Silakan masuk kembali.',
            ], 401),
        ]);

        $exitCode = \Illuminate\Support\Facades\Artisan::call('doku:verify-endpoint', [
            '--url' => 'https://apiudev.udumbara.my.id/api/v1/admin/doku-gateway/merchants',
        ]);

        $this->assertSame(1, $exitCode);
    }

    public function test_admin_can_create_doku_merchant(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $response = $this->postJson('/api/v1/admin/doku-gateway/merchants', [
            'merchant_code' => 'toko-baru',
            'merchant_name' => 'Toko Baru Indonesia',
            'client_id' => 'MALL-TOKO-BARU-01',
            'is_active' => true,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'merchant_code' => 'toko-baru',
                    'merchant_name' => 'Toko Baru Indonesia',
                    'environment' => 'sandbox',
                    'is_configured' => false,
                    'is_active' => true,
                ],
            ]);

        $this->assertDatabaseHas('doku_merchant_credentials', [
            'merchant_code' => 'toko-baru',
            'environment' => 'sandbox',
        ]);

        $this->assertDatabaseHas('doku_gateway_audits', [
            'merchant_code' => 'toko-baru',
            'action' => 'merchant.created',
            'status' => 'success',
        ]);
    }

    public function test_admin_can_update_doku_merchant(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        DokuMerchantCredential::create([
            'merchant_code' => 'toko-edit',
            'merchant_name' => 'Toko Lama',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);

        $response = $this->putJson('/api/v1/admin/doku-gateway/merchants/toko-edit', [
            'merchant_name' => 'Toko Edit Berhasil',
            'is_active' => false,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'merchant_code' => 'toko-edit',
                    'merchant_name' => 'Toko Edit Berhasil',
                    'is_active' => false,
                ],
            ]);

        $this->assertDatabaseHas('doku_merchant_credentials', [
            'merchant_code' => 'toko-edit',
            'merchant_name' => 'Toko Edit Berhasil',
            'is_active' => false,
        ]);
    }

    public function test_admin_can_toggle_doku_merchant_status(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        DokuMerchantCredential::create([
            'merchant_code' => 'toko-toggle',
            'merchant_name' => 'Toko Toggle',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);

        $response = $this->patchJson('/api/v1/admin/doku-gateway/merchants/toko-toggle/status', [
            'is_active' => false,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'merchant_code' => 'toko-toggle',
                    'is_active' => false,
                ],
            ]);

        $this->assertDatabaseHas('doku_merchant_credentials', [
            'merchant_code' => 'toko-toggle',
            'is_active' => false,
        ]);
    }

    public function test_admin_cannot_delete_default_udev_central_merchant(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $response = $this->deleteJson('/api/v1/admin/doku-gateway/merchants/udev-central');

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'failed',
                'message' => 'Merchant default udev-central tidak dapat dihapus.',
            ]);
    }

    public function test_admin_cannot_delete_merchant_with_active_application_mapping(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $app = \App\Models\Application::factory()->create();

        DokuMerchantCredential::create([
            'merchant_code' => 'toko-mapped',
            'merchant_name' => 'Toko Mapped',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);

        \App\Models\ApplicationPaymentGateway::create([
            'application_id' => $app->id,
            'provider' => 'doku',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'toko-mapped',
            'is_active' => true,
        ]);

        $response = $this->deleteJson('/api/v1/admin/doku-gateway/merchants/toko-mapped');

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'failed',
            ]);

        $this->assertDatabaseHas('doku_merchant_credentials', [
            'merchant_code' => 'toko-mapped',
        ]);
    }

    public function test_admin_can_delete_unmapped_doku_merchant(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        DokuMerchantCredential::create([
            'merchant_code' => 'toko-hapus',
            'merchant_name' => 'Toko Hapus',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);

        $response = $this->deleteJson('/api/v1/admin/doku-gateway/merchants/toko-hapus');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);

        $this->assertDatabaseMissing('doku_merchant_credentials', [
            'merchant_code' => 'toko-hapus',
        ]);
    }

    public function test_delete_configuration_resets_credentials_without_deleting_merchant(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        // Konfigurasi awal merchant
        $this->putJson('/api/v1/admin/doku-gateway/configuration', [
            'merchant_code' => 'udev-central',
            'merchant_name' => 'UDEV Central Billing',
            'client_id' => 'MALL-RESET-TEST',
            'private_key' => self::TestPrivateKey,
            'environment' => 'sandbox',
            'is_active' => true,
        ])->assertStatus(200);

        // Hapus konfigurasi
        $response = $this->deleteJson('/api/v1/admin/doku-gateway/configuration?merchant_code=udev-central');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'merchant_code' => 'udev-central',
                    'merchant_name' => 'UDEV Central Billing',
                    'is_configured' => false,
                    'has_private_key' => false,
                ],
            ]);

        // Verifikasi merchant row tetap ada di database
        $merchant = DokuMerchantCredential::where('merchant_code', 'udev-central')->first();
        $this->assertNotNull($merchant);
        $this->assertNull($merchant->client_id);
        $this->assertNull($merchant->encrypted_private_key);
        $this->assertSame('UDEV Central Billing', $merchant->merchant_name);
    }

    public function test_application_payment_gateway_doku_mapping_and_destroy(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $app = \App\Models\Application::factory()->create();

        // Buat mapping DOKU
        $createRes = $this->postJson('/api/v1/admin/payment-gateways', [
            'application_id' => $app->id,
            'provider' => 'doku',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'udev-central',
            'is_active' => true,
        ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('data.provider', 'doku')
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.gateway_merchant_code', 'udev-central');

        $mappingId = $createRes->json('data.id');

        // Hapus mapping DOKU
        $deleteRes = $this->deleteJson("/api/v1/admin/payment-gateways/{$mappingId}");

        $deleteRes->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);

        $this->assertDatabaseMissing('application_payment_gateways', [
            'id' => $mappingId,
        ]);
    }
}
