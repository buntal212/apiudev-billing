<?php

namespace App\Services;

use Carbon\CarbonImmutable;
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
        $environment = strtolower(trim($environment)) === 'production' ? 'production' : 'sandbox';
        $envLabel = ucfirst($environment);

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
                    ->post("{$gatewayUrl}/api/v1/internal/merchants/{$merchantCode}/test-connection?environment={$environment}");

                if ($response->successful()) {
                    return $response->json();
                }
            } catch (ConnectionException) {
                // Gateway service unreachable, fallback to direct Midtrans probe below
            }
        }

        // 2. Direct probe against Midtrans Core API (read-only probe; does not create a transaction)
        $midtransBaseUrl = $environment === 'production'
            ? rtrim((string) config('services.midtrans_gateway.production_base_url', 'https://api.midtrans.com/v2'), '/')
            : rtrim((string) config('services.midtrans_gateway.sandbox_base_url', 'https://api.sandbox.midtrans.com/v2'), '/');
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
                'environment' => $environment,
                'message' => $isAuthValid
                    ? "Koneksi ke Midtrans Core API {$envLabel} berhasil. Kredensial valid."
                    : ($response->json()['status_message'] ?? "Otentikasi ke Midtrans {$envLabel} gagal. Periksa Server Key Anda."),
                'tested_at' => now()->format('Y-m-d H:i:s'),
            ];
        } catch (ConnectionException $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

            return [
                'status' => 'failed',
                'is_connected' => false,
                'http_status' => 504,
                'latency_ms' => $latencyMs,
                'environment' => $environment,
                'message' => "Gagal menghubungi server Midtrans {$envLabel}: ".$e->getMessage(),
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
        $midtransBaseUrl = $environment === 'production'
            ? rtrim((string) config('services.midtrans_gateway.production_base_url', 'https://api.midtrans.com/v2'), '/')
            : rtrim((string) config('services.midtrans_gateway.sandbox_base_url', 'https://api.sandbox.midtrans.com/v2'), '/');
        $orderId = $chargeDetails['order_id'] ?? ('MIDTRANS-TEST-'.now()->format('YmdHis').'-'.Str::random(4));
        $amount = (int) ($chargeDetails['amount'] ?? 10000);
        $paymentType = $chargeDetails['payment_type'] ?? 'qris';
        $bank = strtolower((string) ($chargeDetails['bank'] ?? 'bca'));

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
            if ($bank === 'mandiri') {
                $payload['payment_type'] = 'echannel';
                $payload['echannel'] = [
                    'bill_info1' => 'Payment For:',
                    'bill_info2' => 'UDEV Sandbox',
                ];
            } elseif ($bank === 'permata') {
                $payload['bank_transfer'] = ['bank' => 'permata'];
            } else {
                $payload['bank_transfer'] = ['bank' => $bank];
            }
        } elseif ($paymentType === 'echannel') {
            $payload['echannel'] = [
                'bill_info1' => 'Payment For:',
                'bill_info2' => 'UDEV Sandbox',
            ];
        } elseif ($paymentType === 'gopay') {
            $payload['gopay'] = [
                'enable_callback' => true,
                'callback_url' => 'https://udev.udumbara.my.id/payment-result',
            ];
        }

        $response = Http::withBasicAuth(trim($serverKey), '')
            ->withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json'])
            ->timeout(10)
            ->post("{$midtransBaseUrl}/charge", $payload);

        $body = $response->json() ?? [];
        $statusCode = $response->status();
        $midtransCode = (string) ($body['status_code'] ?? '');

        // If duplicate order ID (406), attempt recovery via Midtrans status query
        if ($statusCode === 406 || $midtransCode === '406') {
            $statusResult = $this->querySandboxStatus($merchantCode, $serverKey, $orderId, $environment);
            if (($statusResult['status'] ?? '') === 'success' && ! empty($statusResult['data']['raw_response'])) {
                $body = $statusResult['data']['raw_response'];
                $statusCode = 200;
                $midtransCode = (string) ($body['status_code'] ?? '200');
            }
        }

        $isSuccess = in_array($midtransCode, ['200', '201'], true)
            || ($statusCode === 201 && $midtransCode === '')
            || ($statusCode >= 200 && $statusCode < 300 && in_array($body['transaction_status'] ?? '', ['pending', 'settlement', 'capture'], true));

        $qrString = $body['qr_string'] ?? ($body['qr_code'] ?? null);
        $checkoutUrl = null;
        $qrUrl = null;
        if (! empty($body['actions'])) {
            foreach ($body['actions'] as $action) {
                if (($action['name'] ?? '') === 'generate-qr-code') {
                    $qrUrl = $action['url'] ?? null;
                    $checkoutUrl = $action['url'] ?? $checkoutUrl;
                } elseif (($action['name'] ?? '') === 'deeplink-redirect') {
                    $checkoutUrl = $action['url'] ?? $checkoutUrl;
                }
            }
        }

        if ($paymentType === 'qris' && empty($qrUrl) && ! empty($body['transaction_id'])) {
            $qrUrl = "https://api.".($environment === 'production' ? '' : 'sandbox.')."midtrans.com/v2/qris/{$body['transaction_id']}/qr-code";
            $checkoutUrl = $checkoutUrl ?: $qrUrl;
        }

        $vaNumbers = $body['va_numbers'] ?? null;
        $permataVaNumber = $body['permata_va_number'] ?? null;
        $billKey = $body['bill_key'] ?? null;
        $billerCode = $body['biller_code'] ?? null;

        if (empty($vaNumbers)) {
            if (! empty($permataVaNumber)) {
                $vaNumbers = [['bank' => 'permata', 'va_number' => $permataVaNumber]];
            } elseif (! empty($billKey)) {
                $vaNumbers = [['bank' => 'mandiri', 'va_number' => $billKey, 'biller_code' => $billerCode]];
            }
        }

        return [
            'status' => $isSuccess ? 'success' : 'failed',
            'message' => $body['status_message'] ?? ($isSuccess ? 'Pembayaran sandbox berhasil dibuat.' : 'Gagal membuat pembayaran sandbox.'),
            'data' => [
                'order_id' => $orderId,
                'transaction_id' => $body['transaction_id'] ?? null,
                'gross_amount' => $amount,
                'payment_type' => $body['payment_type'] ?? $paymentType,
                'bank' => $bank,
                'transaction_status' => $body['transaction_status'] ?? 'pending',
                'qr_string' => $qrString,
                'qr_url' => $qrUrl,
                'checkout_url' => $checkoutUrl,
                'expiry_time' => $body['expiry_time'] ?? null,
                'expires_at' => self::parseMidtransExpiryToUtc($body['expiry_time'] ?? null)?->toIso8601String(),
                'va_numbers' => $vaNumbers,
                'permata_va_number' => $permataVaNumber,
                'bill_key' => $billKey,
                'biller_code' => $billerCode,
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
                    $json = $response->json() ?? [];
                    if (($json['status'] ?? '') === 'success' || ! empty($json['data']['transaction_id'])) {
                        if (! empty($json['data']['expiry_time']) && empty($json['data']['expires_at'])) {
                            $parsedUtc = self::parseMidtransExpiryToUtc($json['data']['expiry_time']);
                            if ($parsedUtc !== null) {
                                $json['data']['expires_at'] = $parsedUtc->toIso8601String();
                            }
                        }

                        return $json;
                    }
                }
            } catch (ConnectionException) {
                // Fallback to direct verification with Midtrans Core API below
            } catch (\Throwable) {
                // Fallback to direct verification with Midtrans Core API below
            }
        }

        $midtransBaseUrl = $environment === 'production'
            ? rtrim((string) config('services.midtrans_gateway.production_base_url', 'https://api.midtrans.com/v2'), '/')
            : rtrim((string) config('services.midtrans_gateway.sandbox_base_url', 'https://api.sandbox.midtrans.com/v2'), '/');

        try {
            $response = Http::withBasicAuth(trim($serverKey), '')
                ->withHeaders(['Accept' => 'application/json'])
                ->timeout(8)
                ->get("{$midtransBaseUrl}/".urlencode($orderId).'/status');

            $body = $response->json() ?? [];
            $statusCode = $response->status();
            $midtransCode = (string) ($body['status_code'] ?? '');

            $isAuthError = in_array($statusCode, [401, 403], true) || in_array($midtransCode, ['401', '403'], true);
            if ($isAuthError) {
                return [
                    'status' => 'failed',
                    'http_status' => $statusCode,
                    'is_not_found' => false,
                    'error_type' => 'gateway_authentication_failed',
                    'message' => $body['status_message'] ?? 'Autentikasi Midtrans gagal. Periksa Server Key merchant.',
                    'data' => [
                        'order_id' => $orderId,
                        'transaction_status' => 'unauthorized',
                        'raw_response' => $body,
                    ],
                ];
            }

            // Only mark is_not_found if Midtrans Core API explicitly returns 404 (and not auth or server error)
            $isNotFound = ($statusCode === 404 || $midtransCode === '404') && ! in_array($statusCode, [401, 403, 500, 502, 503, 504], true);
            $isSuccess = $response->successful() && ! $isNotFound;

            $txId = $body['transaction_id'] ?? null;
            $paymentType = $body['payment_type'] ?? 'qris';
            $qrUrl = null;
            if (! empty($body['actions'])) {
                foreach ($body['actions'] as $action) {
                    if (($action['name'] ?? '') === 'generate-qr-code') {
                        $qrUrl = $action['url'] ?? null;
                        break;
                    }
                }
            }
            if (empty($qrUrl) && $paymentType === 'qris' && ! empty($txId)) {
                $qrUrl = "https://api.".($environment === 'production' ? '' : 'sandbox.')."midtrans.com/v2/qris/{$txId}/qr-code";
            }
            $qrString = $body['qr_string'] ?? ($body['qr_code'] ?? null);
            $checkoutUrl = $qrUrl;

            return [
                'status' => $isSuccess ? 'success' : 'failed',
                'http_status' => $response->status(),
                'is_not_found' => $isNotFound,
                'data' => [
                    'order_id' => $orderId,
                    'transaction_id' => $txId,
                    'transaction_status' => $body['transaction_status'] ?? ($isNotFound ? 'not_found' : 'unknown'),
                    'status_message' => $body['status_message'] ?? null,
                    'gross_amount' => $body['gross_amount'] ?? null,
                    'payment_type' => $paymentType,
                    'qr_string' => $qrString,
                    'qr_url' => $qrUrl,
                    'checkout_url' => $checkoutUrl,
                    'expiry_time' => $body['expiry_time'] ?? null,
                    'expires_at' => self::parseMidtransExpiryToUtc($body['expiry_time'] ?? null)?->toIso8601String(),
                    'va_numbers' => $body['va_numbers'] ?? null,
                    'raw_response' => $body,
                ],
            ];
        } catch (ConnectionException $e) {
            return [
                'status' => 'failed',
                'http_status' => 504,
                'is_not_found' => false,
                'message' => 'Gagal terhubung ke Midtrans API: '.$e->getMessage(),
                'data' => [
                    'order_id' => $orderId,
                    'transaction_status' => 'unknown',
                ],
            ];
        }
    }

    public static function parseMidtransExpiryToUtc(?string $rawExpiry): ?CarbonImmutable
    {
        if (empty($rawExpiry)) {
            return null;
        }

        $rawExpiry = trim($rawExpiry);

        if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $rawExpiry)) {
            return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $rawExpiry, 'Asia/Jakarta')
                ->setTimezone('UTC');
        }

        if (str_contains($rawExpiry, 'T') || str_contains($rawExpiry, '+') || str_ends_with($rawExpiry, 'Z')) {
            return CarbonImmutable::parse($rawExpiry)->setTimezone('UTC');
        }

        try {
            return CarbonImmutable::parse($rawExpiry, 'Asia/Jakarta')->setTimezone('UTC');
        } catch (\Throwable) {
            return null;
        }
    }
}
