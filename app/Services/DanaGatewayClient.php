<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class DanaGatewayClient
{
    /** @return array<string, mixed>|null */
    public function findPaymentByMerchantOrderId(DanaGatewayMerchant $merchant, string $merchantOrderId): ?array
    {
        $response = $this->request($merchant)
            ->get('/api/v1/payments', ['merchant_order_id' => $merchantOrderId]);
        $body = $this->decodeSuccessfulResponse($response);
        $payments = $body['data'] ?? null;

        if (! is_array($payments)) {
            throw new DanaGatewayException('Respons daftar pembayaran gateway tidak valid.', false);
        }

        foreach ($payments as $payment) {
            if (is_array($payment) && ($payment['merchant_order_id'] ?? null) === $merchantOrderId) {
                return $payment;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function createPayment(DanaGatewayMerchant $merchant, string $idempotencyKey, string $merchantOrderId, int $amountMinor, array $metadata): array
    {
        $response = $this->request($merchant)
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->post('/api/v1/payments', [
                'merchant_order_id' => $merchantOrderId,
                'amount_minor' => $amountMinor,
                'currency' => 'IDR',
                'metadata' => $metadata,
            ]);
        $body = $this->decodeSuccessfulResponse($response);
        $payment = $body['data'] ?? null;

        if (! is_array($payment)) {
            throw new DanaGatewayException('Respons pembuatan pembayaran gateway tidak valid.', false);
        }

        return $payment;
    }

    private function request(DanaGatewayMerchant $merchant): PendingRequest
    {
        return Http::acceptJson()
            ->baseUrl($merchant->baseUrl)
            ->withToken($merchant->apiKey)
            ->timeout((int) config('services.dana_gateway.timeout', 8))
            ->connectTimeout((int) config('services.dana_gateway.connect_timeout', 3));
    }

    /** @return array<string, mixed> */
    private function decodeSuccessfulResponse(Response $response): array
    {
        if (! $response->successful()) {
            throw new DanaGatewayException(
                'Gateway DANA menolak atau belum dapat memproses permintaan.',
                $response->status() === 429 || $response->serverError(),
            );
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new DanaGatewayException('Respons gateway DANA bukan JSON yang valid.', false);
        }

        return $body;
    }
}
