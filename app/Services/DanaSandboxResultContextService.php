<?php

namespace App\Services;

use App\Models\DanaMerchantProvisioningAudit;
use App\Models\DanaSandboxResultContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DanaSandboxResultContextService
{
    /** @return array{context: DanaSandboxResultContext, token: string} */
    public function prepare(DanaMerchantProvisioningAudit $merchant, User $admin, string $idempotencyKey): array
    {
        $fingerprint = hash('sha256', $idempotencyKey);
        $context = DanaSandboxResultContext::query()
            ->where('admin_user_id', $admin->getKey())
            ->where('gateway_merchant_id', $merchant->gateway_merchant_id)
            ->where('idempotency_fingerprint', $fingerprint)
            ->first();

        if ($context !== null) {
            return ['context' => $context, 'token' => $this->token()];
        }

        $token = $this->token();
        $context = DanaSandboxResultContext::query()->create([
            'admin_user_id' => $admin->getKey(),
            'gateway_merchant_id' => $merchant->gateway_merchant_id,
            'context_hash' => hash('sha256', $token),
            'idempotency_fingerprint' => $fingerprint,
            'expires_at' => now()->addMinutes(35),
        ]);

        return ['context' => $context, 'token' => $token];
    }

    public function bind(DanaSandboxResultContext $context, string $paymentId, string $returnContext): DanaSandboxResultContext
    {
        if (! hash_equals($context->context_hash, hash('sha256', $returnContext))) {
            throw new DanaMerchantProvisioningException('Konteks hasil Sandbox gateway tidak cocok.', 502, false);
        }

        return DB::transaction(function () use ($context, $paymentId): DanaSandboxResultContext {
            $lockedContext = DanaSandboxResultContext::query()->lockForUpdate()->findOrFail($context->getKey());

            if ($lockedContext->gateway_payment_id !== null && $lockedContext->gateway_payment_id !== $paymentId) {
                throw new DanaMerchantProvisioningException('Konteks hasil Sandbox telah terikat ke transaksi lain.', 502, false);
            }

            $lockedContext->update(['gateway_payment_id' => $paymentId]);

            return $lockedContext->refresh();
        });
    }

    public function forReturn(string $token, User $admin): DanaSandboxResultContext
    {
        $context = DanaSandboxResultContext::query()
            ->where('context_hash', hash('sha256', $token))
            ->where('admin_user_id', $admin->getKey())
            ->where('expires_at', '>', now())
            ->first();

        if ($context === null || $context->gateway_payment_id === null) {
            throw new NotFoundHttpException('Hasil transaksi Sandbox tidak ditemukan atau sudah kedaluwarsa.');
        }

        return $context;
    }

    public function forHistory(int $merchantId, string $paymentId, User $admin): DanaSandboxResultContext
    {
        $context = DanaSandboxResultContext::query()
            ->where('gateway_merchant_id', $merchantId)
            ->where('gateway_payment_id', $paymentId)
            ->where('admin_user_id', $admin->getKey())
            ->where('expires_at', '>', now())
            ->first();

        if ($context === null) {
            throw new AccessDeniedHttpException('Transaksi Sandbox tidak dapat diakses oleh admin ini.');
        }

        return $context;
    }

    private function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
