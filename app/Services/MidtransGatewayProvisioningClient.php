<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class MidtransGatewayProvisioningClient
{
    /**
     * Test connection to Midtrans Sandbox using the configured Server Key.
     * First attempts through the microservice gateway, and falls back to direct API probe.
     *
     * @return array{
     *     status: string,
     *     is_connected: bool,
     *     http_status: int,
     *     latency_ms: int,
     *     message: string,
     *     tested_at: string
     * }
     */
    public function testConnection(string $merchantCode, string $serverKey, string $environment = 'sandbox'): array
    {
        if ($environment !== 'sandbox') {
            throw new RuntimeException('Operasi uji koneksi Midtrans hanya diizinkan untuk lingkungan Sandbox.');
        }

        $gatewayUrl = rtrim((string) config('services.midtrans_gateway.gateway_service_url', 'http://127.0.0.1:8003'), '/');
        $serviceToken = (string) config('services.midtrans_gateway.service_token');
        $startTime = microtime(true);

        // 1. Try calling the internal service gateway if configured
        if ($gatewayUrl !== '' && $serviceToken !== '') {
            try {
                $response = Http::timeout((int) config('services.midtrans_gateway.timeout', 5))
                    ->connectTimeout((int) config('services.midtrans_gateway.connect_timeout', 3))
                    ->withToken($serviceToken)
                    ->acceptJson()
                    ->post("{$gatewayUrl}/api/v1/internal/merchants/{$merchantCode}/test-connection");

                if ($response->successful()) {
                    return $response->json();
                }
            } catch (ConnectionException) {
                // Gateway service unreachable, fallback to direct Midtrans probe below
            }
        }

        // 2. Direct probe against Midtrans Sandbox Core API
        $midtransBaseUrl = rtrim((string) config('services.midtrans_gateway.sandbox_base_url', 'https://api.sandbox.midtrans.com/v2'), '/');
        $testOrderId = 'probe-'.Str::random(8);

        try {
            $response = Http::withBasicAuth(trim($serverKey), '')
                ->withHeaders(['Accept' => 'application/json'])
                ->timeout((int) config('services.midtrans_gateway.timeout', 8))
                ->connectTimeout((int) config('services.midtrans_gateway.connect_timeout', 4))
                ->get("{$midtransBaseUrl}/{$testOrderId}/status");

            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            $statusCode = $response->status();

            // Midtrans 404 means the server key was authenticated successfully, but order does not exist
            $isAuthValid = ($statusCode === 404 || $statusCode === 200);

            return [
                'status' => $isAuthValid ? 'success' : 'failed',
                'is_connected' => $isAuthValid,
                'http_status' => $statusCode,
                'latency_ms' => $latencyMs,
                'message' => $isAuthValid
                    ? 'Koneksi ke Midtrans Core API Sandbox berhasil. Kredensial valid.'
                    : ($response->json()['status_message'] ?? 'Otentikasi ke Midtrans Sandbox gagal. Periksa Server Key Anda.'),
                'tested_at' => now()->format('Y-m-d H:i:s'),
            ];
        } catch (ConnectionException $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

            return [
                'status' => 'failed',
                'is_connected' => false,
                'http_status' => 504,
                'latency_ms' => $latencyMs,
                'message' => 'Gagal menghubungi server Midtrans Sandbox: '.$e->getMessage(),
                'tested_at' => now()->format('Y-m-d H:i:s'),
            ];
        }
    }

    /**
     * Create a sandbox charge transaction.
     *
     * @param  array<string, mixed>  $chargeDetails
     * @return array<string, mixed>
     */
    public function createSandboxCharge(string $merchantCode, string $serverKey, array $chargeDetails, string $environment = 'sandbox'): array
    {
        $gatewayUrl = rtrim((string) config('services.midtrans_gateway.gateway_service_url', 'http://127.0.0.1:8003'), '/');
        $serviceToken = (string) config('services.midtrans_gateway.service_token');

        if ($gatewayUrl !== '' && $serviceToken !== '') {
            try {
                $response = Http::timeout((int) config('services.midtrans_gateway.timeout', 8))
                    ->connectTimeout((int) config('services.midtrans_gateway.connect_timeout', 3))
                    ->withToken($serviceToken)
                    ->acceptJson()
                    ->post("{$gatewayUrl}/api/v1/internal/merchants/{$merchantCode}/sandbox-test/charge", $chargeDetails);

                if ($response->successful()) {
                    return $response->json();
                }
            } catch (ConnectionException) {
                // Fallback to direct call below
            }
        }

        // Direct Core API call
        $midtransBaseUrl = rtrim((string) config('services.midtrans_gateway.sandbox_base_url', 'https://api.sandbox.midtrans.com/v2'), '/');
        $orderId = $chargeDetails['order_id'] ?? ('MIDTRANS-TEST-'.now()->format('YmdHis').'-'.Str::random(4));
        $amount = (int) ($chargeDetails['amount'] ?? 10000);
        $paymentType = $chargeDetails['payment_type'] ?? 'qris';

        $payload = [
            'payment_type' => $paymentType,
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => $amount,
            ],
            'customer_details' => [
                'first_name' => 'UDEV',
                'last_name' => 'Admin Sandbox',
                'email' => 'sandbox@udumbara.my.id',
            ],
        ];

        if ($paymentType === 'qris') {
            $payload['qris'] = ['acquirer' => 'gopay'];
        } elseif ($paymentType === 'bank_transfer') {
            $payload['bank_transfer'] = ['bank' => $chargeDetails['bank'] ?? 'bca'];
        }

        $response = Http::withBasicAuth(trim($serverKey), '')
            ->withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json'])
            ->timeout(10)
            ->post("{$midtransBaseUrl}/charge", $payload);

        $body = $response->json() ?? [];
        $statusCode = $response->status();
        $isSuccess = ($statusCode >= 200 && $statusCode < 300) || ($body['status_code'] ?? '') === '201';

        $qrString = $body['qr_string'] ?? null;
        $checkoutUrl = null;
        if (! empty($body['actions'])) {
            foreach ($body['actions'] as $action) {
                if (($action['name'] ?? '') === 'generate-qr-code') {
                    $checkoutUrl = $action['url'] ?? null;
                }
            }
        }

        return [
            'status' => $isSuccess ? 'success' : 'failed',
            'message' => $body['status_message'] ?? ($isSuccess ? 'Pembayaran sandbox berhasil dibuat.' : 'Gagal membuat pembayaran sandbox.'),
            'data' => [
                'order_id' => $orderId,
                'transaction_id' => $body['transaction_id'] ?? null,
                'gross_amount' => $amount,
                'payment_type' => $paymentType,
                'transaction_status' => $body['transaction_status'] ?? 'pending',
                'qr_string' => $qrString,
                'checkout_url' => $checkoutUrl,
                'va_numbers' => $body['va_numbers'] ?? null,
                'raw_response' => $body,
                'created_at' => now()->format('Y-m-d H:i:s'),
            ],
        ];
    }

    /**
     * Query transaction status from Midtrans Sandbox.
     *
     * @return array<string, mixed>
     */
    public function querySandboxStatus(string $merchantCode, string $serverKey, string $orderId, string $environment = 'sandbox'): array
    {
        $gatewayUrl = rtrim((string) config('services.midtrans_gateway.gateway_service_url', 'http://127.0.0.1:8003'), '/');
        $serviceToken = (string) config('services.midtrans_gateway.service_token');

        if ($gatewayUrl !== '' && $serviceToken !== '') {
            try {
                $response = Http::timeout(5)
                    ->withToken($serviceToken)
                    ->acceptJson()
                    ->get("{$gatewayUrl}/api/v1/internal/merchants/{$merchantCode}/sandbox-test/status/{$orderId}");

                if ($response->successful()) {
                    return $response->json();
                }
            } catch (ConnectionException) {
                // Fallback below
            }
        }

        $midtransBaseUrl = rtrim((string) config('services.midtrans_gateway.sandbox_base_url', 'https://api.sandbox.midtrans.com/v2'), '/');

        $response = Http::withBasicAuth(trim($serverKey), '')
            ->withHeaders(['Accept' => 'application/json'])
            ->timeout(8)
            ->get("{$midtransBaseUrl}/".urlencode($orderId).'/status');

        $body = $response->json() ?? [];
        $isSuccess = $response->successful();

        return [
            'status' => $isSuccess ? 'success' : 'failed',
            'data' => [
                'order_id' => $orderId,
                'transaction_status' => $body['transaction_status'] ?? 'unknown',
                'status_message' => $body['status_message'] ?? null,
                'gross_amount' => $body['gross_amount'] ?? null,
                'payment_type' => $body['payment_type'] ?? null,
                'raw_response' => $body,
            ],
        ];
    }
}
