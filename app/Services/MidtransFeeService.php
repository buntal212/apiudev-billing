<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\MidtransPaymentMethodFee;

class MidtransFeeService
{
    /**
     * Default standard payment method definitions.
     *
     * @var array<string, array{name: string, type: string, rate: float, fixed: float}>
     */
    public const array DefaultMethods = [
        'qris' => [
            'name' => 'QRIS Midtrans',
            'type' => MidtransPaymentMethodFee::TypePercent,
            'rate' => 0.7000,
            'fixed' => 0.00,
        ],
        'va_bca' => [
            'name' => 'BCA Virtual Account',
            'type' => MidtransPaymentMethodFee::TypeFixed,
            'rate' => 0.0000,
            'fixed' => 4000.00,
        ],
        'va_bni' => [
            'name' => 'BNI Virtual Account',
            'type' => MidtransPaymentMethodFee::TypeFixed,
            'rate' => 0.0000,
            'fixed' => 4000.00,
        ],
        'va_bri' => [
            'name' => 'BRI Virtual Account',
            'type' => MidtransPaymentMethodFee::TypeFixed,
            'rate' => 0.0000,
            'fixed' => 4000.00,
        ],
        'va_permata' => [
            'name' => 'Permata Virtual Account',
            'type' => MidtransPaymentMethodFee::TypeFixed,
            'rate' => 0.0000,
            'fixed' => 4000.00,
        ],
        'va_mandiri' => [
            'name' => 'Mandiri Bill Payment',
            'type' => MidtransPaymentMethodFee::TypeFixed,
            'rate' => 0.0000,
            'fixed' => 4000.00,
        ],
        'gopay' => [
            'name' => 'GoPay',
            'type' => MidtransPaymentMethodFee::TypePercent,
            'rate' => 2.0000,
            'fixed' => 0.00,
        ],
        'indomaret' => [
            'name' => 'Indomaret',
            'type' => MidtransPaymentMethodFee::TypeFixed,
            'rate' => 0.0000,
            'fixed' => 5000.00,
        ],
        'cstore' => [
            'name' => 'Indomaret (CStore)',
            'type' => MidtransPaymentMethodFee::TypeFixed,
            'rate' => 0.0000,
            'fixed' => 5000.00,
        ],
    ];

    /**
     * Ensure default fee configurations exist for the given merchant code.
     */
    public function ensureDefaultFeesForMerchant(string $merchantCode = 'udev-central'): void
    {
        foreach (self::DefaultMethods as $method => $def) {
            MidtransPaymentMethodFee::query()->firstOrCreate(
                [
                    'merchant_code' => $merchantCode,
                    'payment_method' => $method,
                ],
                [
                    'payment_method_name' => $def['name'],
                    'fee_type' => $def['type'],
                    'fee_rate' => $def['rate'],
                    'fixed_amount' => $def['fixed'],
                    'min_fee' => null,
                    'max_fee' => null,
                    'is_active' => true,
                    'notes' => 'Standar konfigurasi biaya '.$def['name'],
                ]
            );
        }
    }

    /**
     * Calculate admin fee for a merchant and payment method given a subtotal after discount.
     *
     * @return array{
     *     payment_method: string,
     *     payment_method_name: string,
     *     fee_type: string,
     *     fee_rate: float,
     *     fixed_amount: float,
     *     min_fee: ?float,
     *     max_fee: ?float,
     *     fee_amount: float,
     *     subtotal_amount: float,
     *     total_amount: float,
     *     rule_id: ?int
     * }
     */
    public function calculateFee(string $merchantCode, string $paymentMethod, float|int|string $subtotal): array
    {
        $subtotalFloat = (float) $subtotal;

        // Try merchant-specific rule first, then fallback to 'udev-central'
        $rule = MidtransPaymentMethodFee::query()
            ->where('merchant_code', $merchantCode)
            ->where('payment_method', $paymentMethod)
            ->first();

        if ($rule === null && $merchantCode !== 'udev-central') {
            $rule = MidtransPaymentMethodFee::query()
                ->where('merchant_code', 'udev-central')
                ->where('payment_method', $paymentMethod)
                ->first();
        }

        $feeAmount = $rule !== null ? $rule->calculateFee($subtotalFloat) : 0.0;
        $totalAmount = (float) round($subtotalFloat + $feeAmount);

        $methodName = $rule?->payment_method_name
            ?? self::DefaultMethods[$paymentMethod]['name']
            ?? $this->resolvePaymentMethodLabel($paymentMethod);

        return [
            'payment_method' => $paymentMethod,
            'payment_method_name' => $methodName,
            'fee_type' => $rule?->fee_type ?? MidtransPaymentMethodFee::TypeFixed,
            'fee_rate' => (float) ($rule?->fee_rate ?? 0),
            'fixed_amount' => (float) ($rule?->fixed_amount ?? 0),
            'min_fee' => $rule?->min_fee !== null ? (float) $rule->min_fee : null,
            'max_fee' => $rule?->max_fee !== null ? (float) $rule->max_fee : null,
            'fee_amount' => $feeAmount,
            'subtotal_amount' => $subtotalFloat,
            'total_amount' => $totalAmount,
            'rule_id' => $rule?->id,
        ];
    }

    /**
     * Build an immutable snapshot of fee breakdown for a payment attempt.
     *
     * @return array<string, mixed>
     */
    public function buildFeeSnapshot(
        Invoice $invoice,
        string $paymentMethod,
        ?string $bank = null,
        ?string $merchantCode = null,
    ): array {
        $merchant = $merchantCode ?: 'udev-central';
        $subtotal = (float) $invoice->getRawOriginal('amount');
        $calc = $this->calculateFee($merchant, $paymentMethod, $subtotal);

        $metadata = is_array($invoice->metadata) ? $invoice->metadata : [];
        $basePrice = (float) ($metadata['normal_price'] ?? $subtotal);
        $discountAmount = (float) ($metadata['referral_discount'] ?? 0.0);

        return [
            'package_name' => $invoice->plan?->name ?? 'Paket Langganan',
            'base_price' => $basePrice,
            'discount_amount' => $discountAmount,
            'subtotal_amount' => $subtotal,
            'payment_method' => $paymentMethod,
            'payment_method_label' => $calc['payment_method_name'],
            'bank' => $bank,
            'fee_type' => $calc['fee_type'],
            'fee_rate' => $calc['fee_rate'],
            'fixed_amount' => $calc['fixed_amount'],
            'fee_amount' => $calc['fee_amount'],
            'total_amount' => $calc['total_amount'],
            'currency' => (string) ($invoice->currency ?? 'IDR'),
            'calculated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Resolve human-friendly payment method label.
     */
    public function resolvePaymentMethodLabel(string $paymentMethod): string
    {
        return match ($paymentMethod) {
            'qris' => 'QRIS Midtrans',
            'va_bca' => 'BCA Virtual Account',
            'va_bni' => 'BNI Virtual Account',
            'va_bri' => 'BRI Virtual Account',
            'va_permata' => 'Permata Virtual Account',
            'va_mandiri', 'echannel' => 'Mandiri Bill Payment',
            'gopay' => 'GoPay',
            'indomaret', 'cstore' => 'Indomaret',
            'dana_wallet' => 'DANA Wallet',
            'bank_transfer' => 'Transfer Bank',
            default => strtoupper(str_replace('_', ' ', $paymentMethod)),
        };
    }
}
