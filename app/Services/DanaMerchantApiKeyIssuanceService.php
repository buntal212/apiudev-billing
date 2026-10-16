<?php

namespace App\Services;

use App\Models\DanaMerchantApiKeyIssuance;
use App\Models\DanaMerchantProvisioningAudit;
use Illuminate\Support\Facades\DB;

class DanaMerchantApiKeyIssuanceService
{
    private const array BlockingStatuses = [
        'pending',
        'secret_stored',
        'confirmation_pending',
        'cancel_pending',
        'manual_review',
    ];

    private const array GatewayStatuses = [
        'pending' => 'pending',
        'confirmed' => 'active',
        'cancelled' => 'cancelled',
        'expired' => 'expired',
    ];

    public function __construct(private DanaMerchantProvisioningClient $client, private DanaMerchantApiKeySecretStore $store) {}

    public function issue(DanaMerchantProvisioningAudit $merchant, int $adminId, array $attributes, string $idempotencyKey): DanaMerchantApiKeyIssuance
    {
        $fingerprint = hash_hmac('sha256', json_encode($attributes, JSON_THROW_ON_ERROR), (string) config('app.key'));
        $replayed = false;

        $issuance = DB::transaction(function () use ($merchant, $adminId, $fingerprint, $idempotencyKey, &$replayed): DanaMerchantApiKeyIssuance {
            $existing = DanaMerchantApiKeyIssuance::query()
                ->where('gateway_merchant_id', $merchant->gateway_merchant_id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $replayed = true;

                if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw new DanaMerchantProvisioningException('Idempotency key tidak dapat digunakan untuk data issuance yang berbeda.', 409, false);
                }

                return $existing;
            }

            $blockingIssuance = DanaMerchantApiKeyIssuance::query()
                ->where('gateway_merchant_id', $merchant->gateway_merchant_id)
                ->whereIn('status', self::BlockingStatuses)
                ->lockForUpdate()
                ->first();
            if ($blockingIssuance !== null) {
                throw new DanaMerchantProvisioningException('Masih ada issuance API key yang perlu direkonsiliasi.', 409, false);
            }

            return DanaMerchantApiKeyIssuance::query()->create([
                'admin_user_id' => $adminId,
                'gateway_merchant_id' => $merchant->gateway_merchant_id,
                'merchant_code' => $merchant->merchant_code,
                'environment' => 'sandbox',
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'status' => 'pending',
            ]);
        });
        if ($replayed || $issuance->gateway_issuance_id !== null) {
            return $issuance;
        }
        try {
            $gateway = $this->client->issueApiKey($merchant->gateway_merchant_id, $attributes, $idempotencyKey);
            $data = $gateway['issuance'];
            $apiKeyId = $data['merchant_api_key_id'] ?? null;
            if (! is_numeric($apiKeyId) || ! is_numeric($data['id'] ?? null)) {
                throw new DanaMerchantProvisioningException('Metadata issuance gateway tidak valid.', 502, false);
            } $issuance->update(['gateway_issuance_id' => (int) $data['id'], 'gateway_api_key_id' => (int) $apiKeyId, 'key_prefix' => (string) ($data['key_prefix'] ?? ''), 'status' => 'pending']);
            $this->store->store(['gateway_merchant_id' => $merchant->gateway_merchant_id, 'gateway_api_key_id' => (int) $apiKeyId, 'key_prefix' => (string) ($data['key_prefix'] ?? ''), 'plaintext_api_key' => $gateway['api_key']]);
            $issuance->update(['secret_stored_at' => now(), 'status' => 'confirmation_pending']);

            return $this->confirm($issuance);
        } catch (DanaMerchantProvisioningException|DanaMerchantSecretStoreException $e) {
            $issuance->update(['status' => $issuance->gateway_issuance_id === null ? 'manual_review' : 'cancel_pending']);
            throw $e;
        }
    }

    public function reconcile(DanaMerchantApiKeyIssuance $issuance): DanaMerchantApiKeyIssuance
    {
        if ($issuance->gateway_issuance_id === null) {
            return $issuance;
        }

        $data = $this->client->issuanceStatus($issuance->gateway_merchant_id, $issuance->gateway_issuance_id);
        $status = is_string($data['status'] ?? null) ? $data['status'] : null;

        $issuance->update([
            'status' => self::GatewayStatuses[$status] ?? 'manual_review',
            'cancelled_at' => $status === 'cancelled' ? now() : $issuance->cancelled_at,
        ]);

        return $issuance->fresh();
    }

    public function cancel(DanaMerchantApiKeyIssuance $issuance): DanaMerchantApiKeyIssuance
    {
        if ($issuance->gateway_issuance_id === null) {
            return $issuance;
        } try {
            $this->client->cancelApiKeyIssuance($issuance->gateway_merchant_id, $issuance->gateway_issuance_id, $issuance->idempotency_key.'-cancel');
            $issuance->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        } catch (DanaMerchantProvisioningException $e) {
            $issuance->update(['status' => 'cancel_pending']);
            throw $e;
        }

        return $issuance->fresh();
    }

    private function confirm(DanaMerchantApiKeyIssuance $issuance): DanaMerchantApiKeyIssuance
    {
        try {
            $data = $this->client->confirmApiKeyIssuance($issuance->gateway_merchant_id, (int) $issuance->gateway_issuance_id, $issuance->idempotency_key.'-confirm');
            $issuance->update(['status' => ($data['status'] ?? null) === 'confirmed' ? 'active' : 'manual_review', 'confirmed_at' => now()]);

            return $issuance;
        } catch (DanaMerchantProvisioningException $e) {
            $issuance->update(['status' => 'confirmation_pending']);
            throw $e;
        }
    }
}
