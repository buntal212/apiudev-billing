<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DokuGatewayProvisioningClient
{
    /**
     * Mengirim permintaan uji koneksi SNAP B2B ke service gateway doku-payment
     * menggunakan autentikasi service-to-service aman (Bearer token).
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
     *     tested_at: string,
     *     target_endpoint?: string
     * }
     */
    public function testB2bToken(
        string $merchantCode,
        string $clientId,
        string $privateKey,
        string $environment = 'sandbox',
        bool $forceRefresh = false,
        ?string $overrideGatewayUrl = null
    ): array {
        if ($environment !== 'sandbox') {
            throw new RuntimeException('Operasi DOKU SNAP B2B hanya diizinkan untuk lingkungan Sandbox. Lingkungan Production diblokir.');
        }

        $gatewayUrl = rtrim(
            $overrideGatewayUrl
                ?: config('services.doku_gateway.gateway_service_url')
                ?: 'http://127.0.0.1:8001',
            '/'
        );

        $serviceToken = (string) config('services.doku_gateway.service_token');
        if ($serviceToken === '') {
            throw new RuntimeException('Konfigurasi token autentikasi service-to-service DOKU Gateway belum tersedia.');
        }

        if ($forceRefresh) {
            \Illuminate\Support\Facades\Cache::forget("doku_snap_b2b_token:{$merchantCode}:{$environment}");
        }

        $endpoint = $gatewayUrl . '/api/v1/internal/doku/test-b2b-token';
        $startTime = microtime(true);

        try {
            $response = Http::timeout((int) config('services.doku_gateway.timeout', 10))
                ->connectTimeout((int) config('services.doku_gateway.connect_timeout', 5))
                ->withToken($serviceToken)
                ->acceptJson()
                ->asJson()
                ->post($endpoint, [
                    'merchant_code' => $merchantCode,
                    'client_id' => $clientId,
                    'private_key' => $privateKey,
                    'environment' => 'sandbox',
                    'force_refresh' => $forceRefresh,
                ]);
        } catch (ConnectionException $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

            return [
                'status' => 'failed',
                'http_status' => 503,
                'response_code' => '5037300',
                'response_message' => 'Layanan doku-payment tidak dapat dihubungi.',
                'token_type' => '',
                'expires_in' => 0,
                'buffer_seconds' => 60,
                'cache_ttl_seconds' => 0,
                'is_cached' => false,
                'masked_token' => '',
                'latency_ms' => $latencyMs,
                'tested_at' => now('Asia/Jakarta')->toIso8601String(),
            ];
        }

        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
        $body = $response->json();
        $data = is_array($body) ? ($body['data'] ?? null) : null;

        if ($response->successful() && is_array($data) && ($data['response_code'] ?? '') === '2007300') {
            $data['status'] = $data['status'] ?? ($body['status'] ?? 'success');
            $data['http_status'] = $response->status();
            $data['latency_ms'] = $latencyMs;

            $cacheKey = "doku_snap_b2b_token:{$merchantCode}:{$environment}";
            $ttl = (int) ($data['cache_ttl_seconds'] ?? 840);
            if ($ttl > 0) {
                \Illuminate\Support\Facades\Cache::put($cacheKey, [
                    'cached_at' => now(),
                    'expires_at' => now()->addSeconds($ttl),
                ], $ttl);
            }

            return $data;
        }

        $responseCode = is_array($data) ? (string) ($data['response_code'] ?? '') : (string) $response->status();
        $responseMessage = is_array($data)
            ? (string) ($data['response_message'] ?? ($body['message'] ?? 'Gagal memperoleh token dari DOKU Sandbox.'))
            : (string) ($body['message'] ?? 'Gagal memperoleh token dari DOKU Sandbox.');

        return [
            'status' => 'failed',
            'http_status' => $response->status(),
            'response_code' => $responseCode,
            'response_message' => $responseMessage,
            'token_type' => '',
            'expires_in' => 0,
            'buffer_seconds' => 60,
            'cache_ttl_seconds' => 0,
            'is_cached' => false,
            'masked_token' => '',
            'latency_ms' => $latencyMs,
            'tested_at' => now('Asia/Jakarta')->toIso8601String(),
        ];
    }

    /**
     * Memeriksa status token cache SNAP B2B untuk merchant di Sandbox.
     *
     * @return array{is_active: bool, remaining_seconds: int}
     */
    public function checkCacheStatus(string $merchantCode, string $environment = 'sandbox'): array
    {
        $cacheKey = "doku_snap_b2b_token:{$merchantCode}:{$environment}";
        $cacheData = \Illuminate\Support\Facades\Cache::get($cacheKey);

        if (! is_array($cacheData) || empty($cacheData['expires_at'])) {
            return [
                'is_active' => false,
                'remaining_seconds' => 0,
            ];
        }

        $remaining = max(0, (int) round($cacheData['expires_at']->diffInSeconds(now())));

        return [
            'is_active' => $remaining > 0,
            'remaining_seconds' => $remaining,
        ];
    }
}
