<?php

namespace Tests\Feature;

use App\Models\DokuMerchantCredential;
use App\Models\User;
use App\Services\DokuSecretStore;
use App\Services\DokuSnapB2BClient;
use App\Services\DokuSnapSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DokuSnapEndToEndIntegrationTest extends TestCase
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

    public function test_snap_b2b_client_signs_valid_rsa_and_receives_token(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        $secretStore = app(DokuSecretStore::class);
        $signatureService = app(DokuSnapSignatureService::class);
        $b2bClient = app(DokuSnapB2BClient::class);

        $publicKey = $signatureService->extractPublicKey(self::TestPrivateKey);
        $fingerprint = $signatureService->publicKeyFingerprint($publicKey);
        $encrypted = $secretStore->encryptPrivateKey(self::TestPrivateKey, 'udev-central', 'v1');

        $cred = DokuMerchantCredential::create([
            'merchant_code' => 'udev-central',
            'merchant_name' => 'UDEV Central Billing',
            'client_id' => 'DEV-UDEV-001',
            'environment' => 'sandbox',
            'is_active' => true,
            'encrypted_private_key' => $encrypted['ciphertext'],
            'key_nonce' => $encrypted['nonce'],
            'key_tag' => $encrypted['tag'],
            'key_version' => $encrypted['key_version'],
            'public_key' => $publicKey,
            'public_key_fingerprint' => $fingerprint,
        ]);

        // Simulasikan endpoint SNAP B2B yang memverifikasi signature asli
        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($publicKey, $signatureService) {
            $clientKey = $request->header('X-CLIENT-KEY')[0] ?? '';
            $timestamp = $request->header('X-TIMESTAMP')[0] ?? '';
            $signature = $request->header('X-SIGNATURE')[0] ?? '';

            $stringToSign = $signatureService->formatStringToSign($clientKey, $timestamp);
            $isValid = openssl_verify($stringToSign, base64_decode($signature), $publicKey, OPENSSL_ALGO_SHA256) === 1;

            if (! $isValid) {
                return Http::response([
                    'responseCode' => '4017300',
                    'responseMessage' => 'Unauthorized. Signature Invalid',
                ], 401);
            }

            return Http::response([
                'responseCode' => '2007300',
                'responseMessage' => 'Successful',
                'accessToken' => 'doku_test_token_live_rsa_verified_' . bin2hex(random_bytes(16)),
                'tokenType' => 'Bearer',
                'expiresIn' => 900,
            ], 200);
        });

        $result = $b2bClient->getAccessToken($cred, true);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals('2007300', $result['response_code']);
        $this->assertEquals(900, $result['expires_in']);
        $this->assertEquals(60, $result['buffer_seconds']);
        $this->assertEquals(840, $result['cache_ttl_seconds']);
        $this->assertNotEmpty($result['masked_token']);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', json_encode($result));
    }

    public function test_provisioning_client_communicates_with_gateway_using_service_bearer_auth(): void
    {
        $provisioningClient = app(\App\Services\DokuGatewayProvisioningClient::class);

        Http::fake([
            'http://127.0.0.1:8001/api/v1/internal/doku/test-b2b-token' => Http::response([
                'status' => 'success',
                'data' => [
                    'response_code' => '2007300',
                    'response_message' => 'Successful',
                    'token_type' => 'Bearer',
                    'expires_in' => 900,
                    'buffer_seconds' => 60,
                    'cache_ttl_seconds' => 840,
                    'is_cached' => false,
                    'masked_token' => 'eyJhbGci...********...998877',
                    'target_endpoint' => 'https://api-sandbox.doku.com/authorization/v1/access-token/b2b',
                ],
            ], 200),
        ]);

        $result = $provisioningClient->testB2bToken(
            merchantCode: 'udev-central',
            clientId: 'MALL-1234',
            privateKey: self::TestPrivateKey,
            environment: 'sandbox',
            forceRefresh: true
        );

        $this->assertEquals('success', $result['status']);
        $this->assertEquals('2007300', $result['response_code']);
        $this->assertEquals('https://api-sandbox.doku.com/authorization/v1/access-token/b2b', $result['target_endpoint']);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->hasHeader('Authorization', 'Bearer doku_service_secret_token_sandbox_64chars_key')
                && $request['environment'] === 'sandbox'
                && $request['merchant_code'] === 'udev-central';
        });
    }

    public function test_provisioning_client_blocks_production(): void
    {
        $provisioningClient = app(\App\Services\DokuGatewayProvisioningClient::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Operasi DOKU SNAP B2B hanya diizinkan untuk lingkungan Sandbox. Lingkungan Production diblokir.');

        $provisioningClient->testB2bToken(
            merchantCode: 'udev-central',
            clientId: 'MALL-PROD',
            privateKey: self::TestPrivateKey,
            environment: 'production'
        );
    }
}
