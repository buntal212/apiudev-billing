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
        $paymentCode = $metadata['payment_code'] ?? null;
        $instruction = $metadata['payment_instruction'] ?? null;

        $checkoutStatus = ($this->checkout_url !== null || $qrContent !== null || $paymentCode !== null)
            ? 'ready'
            : ($this->outbox?->status === CheckoutOutboxMessage::StatusFailed ? 'failed' : 'processing');

        return [
            'invoice_id' => $this->invoice_id,
            'payment_attempt_id' => $this->id,
            'gateway_payment_id' => $this->gateway_payment_id,
            'status' => $this->status,
            'payment_method' => $metadata['payment_method'] ?? 'dana_wallet',
            'checkout_status' => $checkoutStatus,
            'checkout_url' => $this->checkout_url,
            'qr_content' => $qrContent,
            'payment_code' => $paymentCode,
            'payment_instruction' => $instruction,
            'expires_at' => $this->checkout_expires_at?->toISOString(),
        ];
    }
}
