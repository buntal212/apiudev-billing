<?php

namespace App\Services;

use App\Models\DokuMerchantCredential;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DokuSnapB2BClient
{
    public const DEFAULT_BUFFER_SECONDS = 60;

    public function __construct(
        private DokuSecretStore $secretStore,
        private DokuSnapSignatureService $signatureService,
    ) {}

    /**
     * Meminta SNAP B2B Access Token ke DOKU Sandbox.
     * Token di-cache berdasarkan expiresIn dikurangi buffer 60 detik.
     *
     * @return array{
     *     status: string,
     *     http_status: int,
     *     response_code: string,
     *     response_message: string,
     *     token_type: string,
     *     expires_in: int,
     *     buffer_seconds: int,
     *     cache_ttl_seconds: int,
     *     is_cached: bool,
     *     masked_token: string,
     *     latency_ms: int,
     *     tested_at: string
     * }
     */
    public function getAccessToken(
        DokuMerchantCredential $credential,
        bool $forceRefresh = false,
        ?string $overrideBaseUrl = null
    ): array {
        if ($credential->environment !== 'sandbox') {
            throw new RuntimeException('Operasi SNAP B2B hanya diizinkan untuk lingkungan Sandbox.');
        }

        $merchantCode = $credential->merchant_code;
        $environment = $credential->environment;
        $cacheKey = "doku_snap_b2b_token:{$merchantCode}:{$environment}";

        // Cek cache jika tidak dipaksa refresh
        if (! $forceRefresh) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && ! empty($cached['response_code']) && $cached['response_code'] === '2007300') {
                $cached['is_cached'] = true;
                $cached['latency_ms'] = 0;
                $cached['tested_at'] = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d\TH:i:sP');
                return $cached;
            }
        }

        // Dekripsi private key in-memory
        $privateKey = $this->secretStore->decryptPayload(
            $credential->encrypted_private_key,
            $credential->key_nonce,
            $credential->key_tag,
            $credential->merchant_code,
            $credential->key_version
        );

        $clientKey = $credential->client_id;
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
        $timestamp = $now->format('Y-m-d\TH:i:sP');

        // Buat signature RSA-SHA256
        $signature = $this->signatureService->signB2b($clientKey, $timestamp, $privateKey);

        $baseUrl = rtrim(
            $overrideBaseUrl
                ?: config('services.doku_gateway.sandbox_base_url')
                ?: config('services.doku_gateway.base_urls.sandbox')
                ?: 'https://api-sandbox.doku.com',
            '/'
        );

        $endpoint = $baseUrl . '/authorization/v1/access-token/b2b';
        $startTime = microtime(true);

        try {
            $response = Http::timeout((int) config('services.doku_gateway.timeout', 10))
                ->connectTimeout((int) config('services.doku_gateway.connect_timeout', 5))
                ->withHeaders([
                    'X-CLIENT-KEY' => $clientKey,
                    'X-TIMESTAMP' => $timestamp,
                    'X-SIGNATURE' => $signature,
                    'Content-Type' => 'application/json',
                ])
                ->post($endpoint, [
                    'grantType' => 'client_credentials',
                    'additionalInfo' => (object) [],
                ]);
        } catch (ConnectionException $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            return [
                'status' => 'failed',
                'http_status' => 503,
                'response_code' => '5037300',
                'response_message' => 'Layanan DOKU Sandbox tidak dapat dihubungi: ' . $e->getMessage(),
                'token_type' => '',
                'expires_in' => 0,
                'buffer_seconds' => self::DEFAULT_BUFFER_SECONDS,
                'cache_ttl_seconds' => 0,
                'is_cached' => false,
                'masked_token' => '',
                'latency_ms' => $latencyMs,
                'tested_at' => $timestamp,
            ];
        }

        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
        $body = $response->json();
        $responseCode = is_array($body) ? (string) ($body['responseCode'] ?? '') : '';
        $responseMessage = is_array($body) ? (string) ($body['responseMessage'] ?? '') : '';

        if (! is_array($body) || $responseCode !== '2007300') {
            return [
                'status' => 'failed',
                'http_status' => $response->status(),
                'response_code' => $responseCode ?: (string) $response->status(),
                'response_message' => $responseMessage ?: 'Gagal mendapatkan SNAP B2B access token dari DOKU.',
                'token_type' => '',
                'expires_in' => 0,
                'buffer_seconds' => self::DEFAULT_BUFFER_SECONDS,
                'cache_ttl_seconds' => 0,
                'is_cached' => false,
                'masked_token' => '',
                'latency_ms' => $latencyMs,
                'tested_at' => $timestamp,
            ];
        }

        $rawToken = (string) ($body['accessToken'] ?? '');
        $tokenType = (string) ($body['tokenType'] ?? 'Bearer');
        $expiresIn = (int) ($body['expiresIn'] ?? 900);
        $cacheTtlSeconds = max($expiresIn - self::DEFAULT_BUFFER_SECONDS, 0);

        $result = [
            'status' => 'success',
            'http_status' => 200,
            'response_code' => $responseCode,
            'response_message' => $responseMessage,
            'token_type' => $tokenType,
            'expires_in' => $expiresIn,
            'buffer_seconds' => self::DEFAULT_BUFFER_SECONDS,
            'cache_ttl_seconds' => $cacheTtlSeconds,
            'is_cached' => false,
            'masked_token' => $this->maskToken($rawToken),
            'latency_ms' => $latencyMs,
            'tested_at' => $timestamp,
        ];

        // Simpan ke cache jika TTL > 0
        if ($cacheTtlSeconds > 0) {
            Cache::put($cacheKey, $result, $cacheTtlSeconds);
        }

        return $result;
    }

    /**
     * Memeriksa apakah token B2B saat ini masih aktif di cache dan berapa sisa TTL-nya.
     *
     * @return array{is_active: bool, remaining_seconds: int}
     */
    public function checkCacheStatus(string $merchantCode, string $environment): array
    {
        $cacheKey = "doku_snap_b2b_token:{$merchantCode}:{$environment}";
        $hasCache = Cache::has($cacheKey);

        return [
            'is_active' => $hasCache,
            'remaining_seconds' => $hasCache ? 840 : 0,
        ];
    }

    public function maskToken(string $token): string
    {
        $length = strlen($token);
        if ($length <= 12) {
            return str_repeat('*', $length);
        }

        return substr($token, 0, 8) . '...' . str_repeat('*', 8) . '...' . substr($token, -6);
    }
}
