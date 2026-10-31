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
                ?: 'https://doku.udumbara.my.id',
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
                'response_message' => "Layanan doku-payment tidak dapat dihubungi ({$gatewayUrl}). Periksa konektivitas jaringan atau konfigurasi URL gateway.",
                'token_type' => '',
                'expires_in' => 0,
                'buffer_seconds' => 60,
                'cache_ttl_seconds' => 0,
                'is_cached' => false,
                'masked_token' => '',
                'latency_ms' => $latencyMs,
                'tested_at' => now('Asia/Jakarta')->toIso8601String(),
                'target_endpoint' => $endpoint,
            ];
        }

        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
        $body = $response->json();
        $data = is_array($body) ? ($body['data'] ?? null) : null;

        if ($response->successful() && is_array($data) && ($data['response_code'] ?? '') === '2007300') {
            $data['status'] = $data['status'] ?? ($body['status'] ?? 'success');
            $data['http_status'] = $response->status();
            $data['latency_ms'] = $latencyMs;
            $data['tested_at'] = $data['tested_at'] ?? now('Asia/Jakarta')->toIso8601String();
            $data['target_endpoint'] = $data['target_endpoint'] ?? $endpoint;

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

        if (is_array($data) && ! empty($data['response_code'])) {
            $data['status'] = $data['status'] ?? 'failed';
            $data['http_status'] = (int) ($data['http_status'] ?? $response->status());
            $data['latency_ms'] = $latencyMs;
            $data['tested_at'] = $data['tested_at'] ?? now('Asia/Jakarta')->toIso8601String();
            $data['target_endpoint'] = $data['target_endpoint'] ?? $endpoint;
            $data['token_type'] = $data['token_type'] ?? '';
            $data['expires_in'] = (int) ($data['expires_in'] ?? 0);
            $data['buffer_seconds'] = (int) ($data['buffer_seconds'] ?? 60);
            $data['cache_ttl_seconds'] = 0;
            $data['is_cached'] = false;
            $data['masked_token'] = $data['masked_token'] ?? '';

            return $data;
        }

        $status = $response->status();
        $rawMessage = is_array($body) ? (string) ($body['message'] ?? '') : '';
        $responseCode = $status . '7300';

        $responseMessage = match ($status) {
            401 => 'Autentikasi antarservice ke doku-payment ditolak (token layanan internal tidak valid).' . ($rawMessage ? " Pesan: {$rawMessage}" : ''),
            403 => 'Otorisasi antarservice ke doku-payment ditolak (izin layanan tidak mencukupi).' . ($rawMessage ? " Pesan: {$rawMessage}" : ''),
            404 => "Endpoint internal doku-payment tidak ditemukan ({$endpoint}). Periksa rute gateway.",
            422 => 'Validasi parameter pada layanan doku-payment gagal.' . ($rawMessage ? " {$rawMessage}" : ''),
            500 => 'Terjadi kesalahan internal pada layanan doku-payment (HTTP 500).' . ($rawMessage ? " {$rawMessage}" : ''),
            502 => 'Layanan doku-payment mengembalikan respons buruk dari upstream/reverse proxy (HTTP 502 Bad Gateway).',
            504 => 'Permintaan ke layanan doku-payment waktu habis melalui proxy/upstream (HTTP 504 Gateway Timeout).',
            default => 'Layanan doku-payment mengembalikan respons HTTP ' . $status . '.' . ($rawMessage ? " {$rawMessage}" : ''),
        };

        return [
            'status' => 'failed',
            'http_status' => $status,
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
            'target_endpoint' => $endpoint,
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
