<?php

namespace Database\Seeders;

use App\Models\DokuMerchantCredential;
use App\Services\DokuSecretStore;
use App\Services\DokuSnapSignatureService;
use Illuminate\Database\Seeder;

class DokuSandboxCredentialSeeder extends Seeder
{
    public const string DefaultSandboxPrivateKey = <<<'PEM'
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

    public function run(): void
    {
        $secretStore = app(DokuSecretStore::class);
        $signatureService = app(DokuSnapSignatureService::class);

        $merchantCode = 'udev-central';
        $publicKey = $signatureService->extractPublicKey(self::DefaultSandboxPrivateKey);
        $fingerprint = $publicKey ? $signatureService->publicKeyFingerprint($publicKey) : null;
        $encrypted = $secretStore->encryptPrivateKey(self::DefaultSandboxPrivateKey, $merchantCode, 'v1');

        DokuMerchantCredential::updateOrCreate(
            [
                'merchant_code' => $merchantCode,
                'environment' => 'sandbox',
            ],
            [
                'merchant_name' => 'UDEV Central Billing',
                'client_id' => 'DEV-UDEV-CENTRAL-001',
                'is_active' => true,
                'encrypted_private_key' => $encrypted['ciphertext'],
                'key_nonce' => $encrypted['nonce'],
                'key_tag' => $encrypted['tag'],
                'key_version' => $encrypted['key_version'],
                'public_key' => $publicKey,
                'public_key_fingerprint' => $fingerprint,
                'last_token_tested_at' => now(),
                'last_token_test_status' => 'success',
                'last_token_test_response_code' => '2007300',
                'last_token_test_message' => 'Successful',
            ]
        );
    }
}
