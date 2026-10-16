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
     * @param  array{environment: string, partner_id: string, merchant_id: string, private_key: string, dana_webhook_public_key: string, origin: string, channel_id: string, notification_url: string, callback_url: string, mcc: string, enabled: bool}  $attributes
     */
    public function stableDanaConfigurationIdempotencyKey(int $merchantId, array $attributes): string
    {
        ksort($attributes);

        return 'udev-dana-configuration-'.hash_hmac('sha256', json_encode([
            'gateway_merchant_id' => $merchantId,
            ...$attributes,
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function stableApiKeyRevocationIdempotencyKey(int $merchantId, int $apiKeyId): string
    {
        return 'udev-api-key-revocation-'.hash_hmac('sha256', json_encode([
            'gateway_merchant_id' => $merchantId,
            'api_key_id' => $apiKeyId,
            'confirm_revoke' => true,
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
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

    /**
     * @return array{merchant_code: string, merchant_status: string, environment: string, active_api_key_count: int, dana_configuration: array{available: bool, enabled: bool, ready: bool}, readiness_status: string, not_ready_components: list<string>}
     */
    public function readiness(int $merchantId): array
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->get("/api/v1/internal/merchants/{$merchantId}/readiness"));
        $body = $this->decodeSuccessfulResponse($response);

        return $this->readinessData($body['data'] ?? null);
    }

    /**
     * @param  array{environment: string, partner_id: string, merchant_id: string, private_key: string, dana_webhook_public_key: string, origin: string, channel_id: string, notification_url: string, callback_url: string, mcc: string, enabled: bool}  $attributes
     * @return array{merchant_code: string, environment: string, dana_configuration: array{available: bool, enabled: bool, ready: bool}}
     */
    public function configureDanaSandbox(int $merchantId, array $attributes, string $idempotencyKey): array
    {
        $response = $this->send(function (PendingRequest $request) use ($merchantId, $attributes, $idempotencyKey): Response {
            return $request->put("/api/v1/internal/merchants/{$merchantId}/dana-configuration", [
                ...$attributes,
                'idempotency_key' => $idempotencyKey,
            ]);
        });
        $body = $this->decodeSuccessfulResponse($response);

        return $this->danaConfiguration($body['data'] ?? null);
    }

    /**
     * @return list<array{id: int, name: string, key_prefix: string, abilities: list<string>, is_active: bool, expires_at: string|null, revoked_at: string|null, last_used_at: string|null, created_at: string|null}>
     */
    public function apiKeys(int $merchantId): array
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->get("/api/v1/internal/merchants/{$merchantId}/api-keys"));
        $body = $this->decodeSuccessfulResponse($response);
        $data = $body['data'] ?? null;

        if (! is_array($data)) {
            throw new DanaMerchantProvisioningException('Respons metadata API key gateway tidak valid.', 502, false, $response->status());
        }

        return array_map(fn (mixed $apiKey): array => $this->apiKey($apiKey), $data);
    }

    /**
     * @return array{id: int, key_prefix: string, revoked_at: string|null}
     */
    public function revokeApiKey(int $merchantId, int $apiKeyId, string $idempotencyKey): array
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->delete(
            "/api/v1/internal/merchants/{$merchantId}/api-keys/{$apiKeyId}",
            ['idempotency_key' => $idempotencyKey, 'confirm_revoke' => true],
        ));
        $body = $this->decodeSuccessfulResponse($response);
        $apiKey = $body['data'] ?? null;

        if (! is_array($apiKey)
            || ! is_int($apiKey['id'] ?? null)
            || ! is_string($apiKey['key_prefix'] ?? null)
            || (! is_string($apiKey['revoked_at'] ?? null) && $apiKey['revoked_at'] !== null)) {
            throw new DanaMerchantProvisioningException('Respons pencabutan API key gateway tidak valid.', 502, false, $response->status());
        }

        return [
            'id' => $apiKey['id'],
            'key_prefix' => $apiKey['key_prefix'],
            'revoked_at' => $apiKey['revoked_at'],
        ];
    }

    public function issueApiKey(int $merchantId, array $attributes, string $idempotencyKey): array
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->withHeaders(['Idempotency-Key' => $idempotencyKey])->post("/api/v1/internal/merchants/{$merchantId}/api-keys", $attributes));
        $data = $this->decodeSuccessfulResponse($response)['data'] ?? null;
        if (! is_array($data) || ! is_array($data['issuance'] ?? null) || ! is_string($data['api_key'] ?? null)) {
            throw new DanaMerchantProvisioningException('Respons penerbitan API key gateway tidak valid.', 502, false, $response->status());
        }

        return ['issuance' => $data['issuance'], 'api_key' => $data['api_key']];
    }

    public function issuanceStatus(int $merchantId, int $issuanceId): array
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->get("/api/v1/internal/merchants/{$merchantId}/api-key-issuances/{$issuanceId}"));
        $data = $this->decodeSuccessfulResponse($response)['data'] ?? null;
        if (! is_array($data)) {
            throw new DanaMerchantProvisioningException('Respons status issuance gateway tidak valid.', 502, false, $response->status());
        }

        return $data;
    }

    public function confirmApiKeyIssuance(int $merchantId, int $issuanceId, string $key): array
    {
        return $this->mutateIssuance($merchantId, $issuanceId, 'confirm', 'confirm_storage', $key);
    }

    public function cancelApiKeyIssuance(int $merchantId, int $issuanceId, string $key): array
    {
        return $this->mutateIssuance($merchantId, $issuanceId, 'cancel', 'confirm_cancel', $key);
    }

    private function mutateIssuance(int $merchantId, int $issuanceId, string $action, string $field, string $key): array
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->withHeaders(['Idempotency-Key' => $key])->post("/api/v1/internal/merchants/{$merchantId}/api-key-issuances/{$issuanceId}/{$action}", [$field => true]));
        $data = $this->decodeSuccessfulResponse($response)['data'] ?? null;
        if (! is_array($data)) {
            throw new DanaMerchantProvisioningException('Respons konfirmasi issuance gateway tidak valid.', 502, false, $response->status());
        }

        return $data;
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

    /**
     * @return array{merchant_code: string, merchant_status: string, environment: string, active_api_key_count: int, dana_configuration: array{available: bool, enabled: bool, ready: bool}, readiness_status: string, not_ready_components: list<string>}
     */
    private function readinessData(mixed $readiness): array
    {
        $configuration = is_array($readiness) ? ($readiness['dana_configuration'] ?? null) : null;
        $components = is_array($readiness) ? ($readiness['not_ready_components'] ?? null) : null;

        if (! is_array($readiness)
            || ! is_string($readiness['merchant_code'] ?? null)
            || ! is_string($readiness['merchant_status'] ?? null)
            || ! is_string($readiness['environment'] ?? null)
            || ! is_int($readiness['active_api_key_count'] ?? null)
            || ! is_array($configuration)
            || ! is_bool($configuration['available'] ?? null)
            || ! is_bool($configuration['enabled'] ?? null)
            || ! is_bool($configuration['ready'] ?? null)
            || ! is_string($readiness['readiness_status'] ?? null)
            || ! is_array($components)
            || collect($components)->contains(fn (mixed $component): bool => ! is_string($component))) {
            throw new DanaMerchantProvisioningException('Respons readiness merchant gateway tidak valid.', 502, false);
        }

        return [
            'merchant_code' => $readiness['merchant_code'],
            'merchant_status' => $readiness['merchant_status'],
            'environment' => $readiness['environment'],
            'active_api_key_count' => $readiness['active_api_key_count'],
            'dana_configuration' => [
                'available' => $configuration['available'],
                'enabled' => $configuration['enabled'],
                'ready' => $configuration['ready'],
            ],
            'readiness_status' => $readiness['readiness_status'],
            'not_ready_components' => array_values($components),
        ];
    }

    /**
     * @return array{merchant_code: string, environment: string, dana_configuration: array{available: bool, enabled: bool, ready: bool}}
     */
    private function danaConfiguration(mixed $configuration): array
    {
        $status = is_array($configuration) ? ($configuration['dana_configuration'] ?? null) : null;

        if (! is_array($configuration)
            || ! is_string($configuration['merchant_code'] ?? null)
            || ! is_string($configuration['environment'] ?? null)
            || ! is_array($status)
            || ! is_bool($status['available'] ?? null)
            || ! is_bool($status['enabled'] ?? null)
            || ! is_bool($status['ready'] ?? null)) {
            throw new DanaMerchantProvisioningException('Respons konfigurasi DANA gateway tidak valid.', 502, false);
        }

        return [
            'merchant_code' => $configuration['merchant_code'],
            'environment' => $configuration['environment'],
            'dana_configuration' => [
                'available' => $status['available'],
                'enabled' => $status['enabled'],
                'ready' => $status['ready'],
            ],
        ];
    }

    /**
     * @return array{id: int, name: string, key_prefix: string, abilities: list<string>, is_active: bool, expires_at: string|null, revoked_at: string|null, last_used_at: string|null, created_at: string|null}
     */
    private function apiKey(mixed $apiKey): array
    {
        $abilities = is_array($apiKey) ? ($apiKey['abilities'] ?? null) : null;
        $dateFields = ['expires_at', 'revoked_at', 'last_used_at', 'created_at'];

        if (! is_array($apiKey)
            || ! is_int($apiKey['id'] ?? null)
            || ! is_string($apiKey['name'] ?? null)
            || ! is_string($apiKey['key_prefix'] ?? null)
            || ! is_array($abilities)
            || ! is_bool($apiKey['is_active'] ?? null)
            || collect($abilities)->contains(fn (mixed $ability): bool => ! is_string($ability))) {
            throw new DanaMerchantProvisioningException('Respons metadata API key gateway tidak valid.', 502, false);
        }

        foreach ($dateFields as $field) {
            if (! is_string($apiKey[$field] ?? null) && $apiKey[$field] !== null) {
                throw new DanaMerchantProvisioningException('Respons metadata API key gateway tidak valid.', 502, false);
            }
        }

        return [
            'id' => $apiKey['id'],
            'name' => $apiKey['name'],
            'key_prefix' => $apiKey['key_prefix'],
            'abilities' => array_values($abilities),
            'is_active' => $apiKey['is_active'],
            'expires_at' => $apiKey['expires_at'],
            'revoked_at' => $apiKey['revoked_at'],
            'last_used_at' => $apiKey['last_used_at'],
            'created_at' => $apiKey['created_at'],
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
