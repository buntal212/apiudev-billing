<?php

namespace App\Http\Resources;

use App\Models\CheckoutOutboxMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceCheckoutResource extends JsonResource
{
    public function __construct(mixed $resource, private readonly ?CheckoutOutboxMessage $outbox = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $qrContent = $metadata['qr_content'] ?? null;
        $instruction = $metadata['payment_instruction'] ?? null;
        $qrUrl = $metadata['qr_url']
            ?? $instruction['qr_url']
            ?? ($this->provider === 'midtrans' && ($metadata['payment_type'] ?? null) === 'qris' && ! empty($this->gateway_payment_id)
                ? "https://api.".($this->gateway_environment === 'production' ? '' : 'sandbox.')."midtrans.com/v2/qris/{$this->gateway_payment_id}/qr-code"
                : null);
        $paymentCode = $metadata['payment_code'] ?? null;

        $isExpired = ($this->status === \App\Models\PaymentAttempt::StatusExpired)
            || ($this->checkout_expires_at?->isPast() ?? false);

        $checkoutStatus = $isExpired
            ? 'expired'
            : (($this->checkout_url !== null || $qrContent !== null || $qrUrl !== null || $paymentCode !== null)
                ? 'ready'
                : ($this->outbox?->status === CheckoutOutboxMessage::StatusFailed ? 'failed' : 'processing'));

        $qrImageUrl = $this->provider === 'midtrans' && ! empty($this->id) && ! $isExpired
            ? url("/api/v1/admin/payment-attempts/{$this->id}/qr-image")
            : null;

        return [
            'invoice_id' => $this->invoice_id,
            'payment_attempt_id' => $this->id,
            'gateway_payment_id' => $this->gateway_payment_id,
            'transaction_id' => $metadata['transaction_id'] ?? $this->gateway_payment_id,
            'status' => $this->status,
            'payment_method' => $metadata['payment_method'] ?? 'dana_wallet',
            'amount' => $this->amount,
            'subtotal_amount' => (float) ($metadata['fee_breakdown']['subtotal_amount'] ?? $this->amount),
            'admin_fee' => (float) ($metadata['fee_breakdown']['fee_amount'] ?? 0.0),
            'fee_breakdown' => $metadata['fee_breakdown'] ?? null,
            'checkout_status' => $checkoutStatus,
            'checkout_url' => $this->checkout_url,
            'qr_content' => $qrContent,
            'qr_string' => $qrContent,
            'qr_url' => $qrUrl,
            'qr_image_url' => $qrImageUrl,
            'payment_code' => $paymentCode,
            'payment_instruction' => $instruction,
            'expires_at' => $this->checkout_expires_at?->toISOString(),
            'is_expired' => $isExpired,
        ];
    }
}
