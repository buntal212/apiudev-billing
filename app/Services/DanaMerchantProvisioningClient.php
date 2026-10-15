<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class DanaMerchantProvisioningClient
{
    /**
     * @param  array{code: string, name: string, email: string|null, environment: string}  $attributes
     */
    public function stableIdempotencyKey(array $attributes): string
    {
        return 'udev-merchant-'.hash('sha256', json_encode([
            'code' => $attributes['code'],
            'name' => $attributes['name'],
            'email' => $attributes['email'],
            'environment' => $attributes['environment'],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array{code: string, name: string, email: string|null, environment: string}  $attributes
     * @return array{merchant: array{merchant_id: int, merchant_code: string, merchant_name: string, environment: string, merchant_status: string, provisioning_status: string, credential_status: string, dana_configuration_status: string, provisioned_at: string|null}, status: int}
     */
    public function provision(array $attributes, string $idempotencyKey): array
    {
        $response = $this->send(function (PendingRequest $request) use ($attributes, $idempotencyKey): Response {
            return $request->post('/api/v1/internal/merchants', [
                ...$attributes,
                'idempotency_key' => $idempotencyKey,
            ]);
        });

        $body = $this->decodeSuccessfulResponse($response);

        return [
            'merchant' => $this->merchant($body['data'] ?? null),
            'status' => $response->status(),
        ];
    }

    /**
     * @param  array{search?: string, status?: string, environment?: string, per_page?: int}  $filters
     * @return array{merchants: list<array{merchant_id: int, merchant_code: string, merchant_name: string, environment: string, merchant_status: string, provisioning_status: string, credential_status: string, dana_configuration_status: string, provisioned_at: string|null}>, meta: array<string, int>}
     */
    public function list(array $filters): array
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->get('/api/v1/internal/merchants', $filters));
        $body = $this->decodeSuccessfulResponse($response);
        $data = $body['data'] ?? null;

        if (! is_array($data)) {
            throw new DanaMerchantProvisioningException('Respons daftar merchant gateway tidak valid.', 502, false, $response->status());
        }

        return [
            'merchants' => array_map(fn (mixed $merchant): array => $this->merchant($merchant), $data),
            'meta' => $this->paginationMeta($body['meta'] ?? null),
        ];
    }

    /**
     * @return array{merchant_id: int, merchant_code: string, merchant_name: string, environment: string, merchant_status: string, provisioning_status: string, credential_status: string, dana_configuration_status: string, provisioned_at: string|null}
     */
    public function find(int $merchantId): array
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->get("/api/v1/internal/merchants/{$merchantId}"));
        $body = $this->decodeSuccessfulResponse($response);

        return $this->merchant($body['data'] ?? null);
    }

    private function request(): PendingRequest
    {
        $baseUrl = config('services.dana_merchant_provisioning.base_url');
        $token = config('services.dana_merchant_provisioning.service_token');
        $scheme = is_string($baseUrl) ? parse_url($baseUrl, PHP_URL_SCHEME) : null;
        $host = is_string($baseUrl) ? parse_url($baseUrl, PHP_URL_HOST) : null;

        if (! is_string($baseUrl) || $baseUrl === '' || $scheme !== 'https' || ! is_string($host) || $host === '') {
            throw new DanaMerchantProvisioningException('Konfigurasi koneksi provisioning merchant gateway belum tersedia.', 503, false);
        }

        if (! is_string($token) || $token === '') {
            throw new DanaMerchantProvisioningException('Konfigurasi autentikasi provisioning merchant gateway belum tersedia.', 503, false);
        }

        return Http::acceptJson()
            ->baseUrl(rtrim($baseUrl, '/'))
            ->withToken($token)
            ->timeout((int) config('services.dana_merchant_provisioning.timeout', 8))
            ->connectTimeout((int) config('services.dana_merchant_provisioning.connect_timeout', 3));
    }

    /** @param  callable(PendingRequest): Response  $send */
    private function send(callable $send): Response
    {
        try {
            return $send($this->request());
        } catch (ConnectionException) {
            throw new DanaMerchantProvisioningException('Gateway provisioning merchant tidak dapat dihubungi.', 503, true);
        }
    }

    /** @return array<string, mixed> */
    private function decodeSuccessfulResponse(Response $response): array
    {
        if (! $response->successful()) {
            throw $this->exceptionFor($response);
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new DanaMerchantProvisioningException('Respons provisioning merchant gateway tidak valid.', 502, false, $response->status());
        }

        return $body;
    }

    private function exceptionFor(Response $response): DanaMerchantProvisioningException
    {
        return match ($response->status()) {
            404 => new DanaMerchantProvisioningException('Merchant gateway tidak ditemukan.', 404, false, 404),
            409 => new DanaMerchantProvisioningException('Permintaan provisioning merchant berkonflik dengan data yang sudah ada.', 409, false, 409),
            422 => new DanaMerchantProvisioningException('Data provisioning merchant ditolak oleh gateway.', 422, false, 422),
            401, 403 => new DanaMerchantProvisioningException('Autentikasi provisioning merchant gateway gagal.', 502, false, $response->status()),
            429 => new DanaMerchantProvisioningException('Gateway provisioning merchant sedang membatasi permintaan. Coba lagi nanti.', 503, true, 429),
            default => new DanaMerchantProvisioningException('Gateway provisioning merchant sedang mengalami gangguan.', 503, true, $response->status()),
        };
    }

    /**
     * @return array{merchant_id: int, merchant_code: string, merchant_name: string, environment: string, merchant_status: string, provisioning_status: string, credential_status: string, dana_configuration_status: string, provisioned_at: string|null}
     */
    private function merchant(mixed $merchant): array
    {
        if (! is_array($merchant)
            || ! is_int($merchant['merchant_id'] ?? null)
            || ! is_string($merchant['merchant_code'] ?? null)
            || ! is_string($merchant['merchant_name'] ?? null)
            || ! is_string($merchant['environment'] ?? null)
            || ! is_string($merchant['merchant_status'] ?? null)
            || ! is_string($merchant['provisioning_status'] ?? null)
            || ! is_string($merchant['credential_status'] ?? null)
            || ! is_string($merchant['dana_configuration_status'] ?? null)
            || ! array_key_exists('provisioned_at', $merchant)
            || (! is_string($merchant['provisioned_at'] ?? null) && $merchant['provisioned_at'] !== null)) {
            throw new DanaMerchantProvisioningException('Respons metadata merchant gateway tidak valid.', 502, false);
        }

        return [
            'merchant_id' => $merchant['merchant_id'],
            'merchant_code' => $merchant['merchant_code'],
            'merchant_name' => $merchant['merchant_name'],
            'environment' => $merchant['environment'],
            'merchant_status' => $merchant['merchant_status'],
            'provisioning_status' => $merchant['provisioning_status'],
            'credential_status' => $merchant['credential_status'],
            'dana_configuration_status' => $merchant['dana_configuration_status'],
            'provisioned_at' => $merchant['provisioned_at'],
        ];
    }

    /** @return array<string, int> */
    private function paginationMeta(mixed $meta): array
    {
        if (! is_array($meta)) {
            return [];
        }

        return collect($meta)
            ->only(['current_page', 'from', 'last_page', 'per_page', 'to', 'total'])
            ->filter(fn (mixed $value): bool => is_int($value))
            ->all();
    }
}
