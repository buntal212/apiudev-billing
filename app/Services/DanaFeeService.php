<?php

namespace App\Services;

use App\Models\DanaPaymentMethodFee;
use App\Models\Invoice;
use Illuminate\Support\Facades\Schema;

class DanaFeeService
{
    /**
     * Default standard payment method fee definitions for DANA.
     *
     * @var array<string, array{name: string, merchant_type: string, merchant_rate: float, merchant_fixed: float, customer_type: string, customer_rate: float, customer_fixed: float, customer_policy: string}>
     */
    public const array DefaultMethods = [
        'qris' => [
            'name' => 'QRIS DANA',
            'merchant_type' => DanaPaymentMethodFee::TypePercent,
            'merchant_rate' => 0.7000,
            'merchant_fixed' => 0.00,
            'customer_type' => DanaPaymentMethodFee::TypeFixed,
            'customer_rate' => 0.0000,
            'customer_fixed' => 0.00,
            'customer_policy' => 'absorbed_by_merchant',
        ],
        'dana_wallet' => [
            'name' => 'DANA Wallet',
            'merchant_type' => DanaPaymentMethodFee::TypePercent,
            'merchant_rate' => 1.5000,
            'merchant_fixed' => 0.00,
            'customer_type' => DanaPaymentMethodFee::TypeFixed,
            'customer_rate' => 0.0000,
            'customer_fixed' => 0.00,
            'customer_policy' => 'zero_surcharge_policy',
        ],
        'va_bri' => [
            'name' => 'Virtual Account BRI',
            'merchant_type' => DanaPaymentMethodFee::TypeFixed,
            'merchant_rate' => 0.0000,
            'merchant_fixed' => 4000.00,
            'customer_type' => DanaPaymentMethodFee::TypeFixed,
            'customer_rate' => 0.0000,
            'customer_fixed' => 0.00,
            'customer_policy' => 'absorbed_by_merchant',
        ],
        'va_pani' => [
            'name' => 'Virtual Account PANI',
            'merchant_type' => DanaPaymentMethodFee::TypeFixed,
            'merchant_rate' => 0.0000,
            'merchant_fixed' => 4000.00,
            'customer_type' => DanaPaymentMethodFee::TypeFixed,
            'customer_rate' => 0.0000,
            'customer_fixed' => 0.00,
            'customer_policy' => 'absorbed_by_merchant',
        ],
        'va_cimb' => [
            'name' => 'Virtual Account CIMB',
            'merchant_type' => DanaPaymentMethodFee::TypeFixed,
            'merchant_rate' => 0.0000,
            'merchant_fixed' => 4000.00,
            'customer_type' => DanaPaymentMethodFee::TypeFixed,
            'customer_rate' => 0.0000,
            'customer_fixed' => 0.00,
            'customer_policy' => 'absorbed_by_merchant',
        ],
        'va_btpn' => [
            'name' => 'Virtual Account BTPN',
            'merchant_type' => DanaPaymentMethodFee::TypeFixed,
            'merchant_rate' => 0.0000,
            'merchant_fixed' => 4000.00,
            'customer_type' => DanaPaymentMethodFee::TypeFixed,
            'customer_rate' => 0.0000,
            'customer_fixed' => 0.00,
            'customer_policy' => 'absorbed_by_merchant',
        ],
    ];

    /**
     * Calculate all fee components for a given subtotal, merchant, and method.
     *
     * @return array{
     *     payment_method: string,
     *     payment_method_name: string,
     *     customer_fee: float,
     *     fee_amount: float,
     *     fee_rate: float,
     *     subtotal_amount: float,
     *     total_amount: float,
     *     customer_fee_policy: string,
     *     merchant_fee_type: string,
     *     merchant_fee_rate: float,
     *     merchant_fixed_amount: float,
     *     estimated_merchant_fee: float,
     *     estimated_net_settlement: float,
     *     rule_id: ?int
     * }
     */
    public function calculateFee(string $merchantCode, string $paymentMethod, float|int|string $subtotal): array
    {
        $subtotalFloat = (float) $subtotal;
        $rule = null;

        if (Schema::hasTable('dana_payment_method_fees')) {
            $rule = DanaPaymentMethodFee::query()
                ->where('merchant_code', $merchantCode)
                ->where('payment_method', $paymentMethod)
                ->first();

            if ($rule === null && $merchantCode !== 'udev-central') {
                $rule = DanaPaymentMethodFee::query()
                    ->where('merchant_code', 'udev-central')
                    ->where('payment_method', $paymentMethod)
                    ->first();
            }
        }

        $default = self::DefaultMethods[$paymentMethod] ?? [
            'name' => $this->resolvePaymentMethodLabel($paymentMethod),
            'merchant_type' => DanaPaymentMethodFee::TypePercent,
            'merchant_rate' => 0.7000,
            'merchant_fixed' => 0.00,
            'customer_type' => DanaPaymentMethodFee::TypeFixed,
            'customer_rate' => 0.0000,
            'customer_fixed' => 0.00,
            'customer_policy' => 'absorbed_by_merchant',
        ];

        // Surcharge & fee calculation:
        // 1. QRIS: MDR absorbed by merchant, customer fee is strictly 0.0
        // 2. DANA Wallet: default Rp0 customer fee until confirmed
        if ($rule !== null) {
            $customerFee = $paymentMethod === 'qris' ? 0.0 : $rule->calculateCustomerFee($subtotalFloat);
            $merchantFee = $rule->calculateMerchantFee($subtotalFloat);
            $methodName = $rule->payment_method_name;
            $merchantFeeType = $rule->merchant_fee_type;
            $merchantFeeRate = (float) $rule->merchant_fee_rate;
            $merchantFixed = (float) $rule->merchant_fixed_amount;
            $policy = $rule->notes ?: ($paymentMethod === 'qris' ? 'absorbed_by_merchant' : 'zero_surcharge_policy');
            $ruleId = $rule->id;
        } else {
            // Check config override if present
            $configuredRates = config('services.dana_gateway.fees.'.$paymentMethod, []);
            $merchantFeeType = $configuredRates['merchant_type'] ?? $default['merchant_type'];
            $merchantFeeRate = (float) ($configuredRates['merchant_rate'] ?? $default['merchant_rate']);
            $merchantFixed = (float) ($configuredRates['merchant_fixed'] ?? $default['merchant_fixed']);
            $customerFee = 0.0; // strictly 0 for both QRIS and DANA Wallet

            if ($merchantFeeType === DanaPaymentMethodFee::TypePercent) {
                $merchantFee = (float) round($subtotalFloat * ($merchantFeeRate / 100));
            } else {
                $merchantFee = (float) round($merchantFixed);
            }

            $methodName = $configuredRates['name'] ?? $default['name'];
            $policy = $default['customer_policy'];
            $ruleId = null;
        }

        $totalPayable = (float) round($subtotalFloat + $customerFee);
        $netSettlement = max(0.0, (float) round($totalPayable - $merchantFee));

        return [
            'payment_method' => $paymentMethod,
            'payment_method_name' => $methodName,
            'customer_fee' => $customerFee,
            'fee_amount' => $customerFee, // backward compatibility
            'fee_rate' => 0.0,
            'subtotal_amount' => $subtotalFloat,
            'total_amount' => $totalPayable,
            'customer_fee_policy' => $policy,
            'merchant_fee_type' => $merchantFeeType,
            'merchant_fee_rate' => $merchantFeeRate,
            'merchant_fixed_amount' => $merchantFixed,
            'estimated_merchant_fee' => $merchantFee,
            'estimated_net_settlement' => $netSettlement,
            'rule_id' => $ruleId,
        ];
    }

    /**
     * Build an immutable snapshot of fee breakdown for a DANA payment attempt.
     *
     * @return array<string, mixed>
     */
    public function buildFeeSnapshot(
        Invoice $invoice,
        string $paymentMethod,
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
            'provider' => 'dana',
            'customer_fee' => $calc['customer_fee'],
            'fee_amount' => $calc['customer_fee'], // backward compatibility
            'fee_rate' => 0.0,
            'fee_type' => 'fixed',
            'total_amount' => $calc['total_amount'],
            'customer_fee_policy' => $calc['customer_fee_policy'],
            'merchant_fee_type' => $calc['merchant_fee_type'],
            'merchant_fee_rate' => $calc['merchant_fee_rate'],
            'merchant_fixed_amount' => $calc['merchant_fixed_amount'],
            'estimated_merchant_fee' => $calc['estimated_merchant_fee'],
            'estimated_net_settlement' => $calc['estimated_net_settlement'],
            'currency' => (string) ($invoice->currency ?? 'IDR'),
            'calculated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Resolve human-friendly payment method label for DANA.
     */
    public function resolvePaymentMethodLabel(string $paymentMethod): string
    {
        return match ($paymentMethod) {
            'qris' => 'QRIS DANA',
            'dana_wallet' => 'DANA Wallet',
            'va_bri' => 'Virtual Account BRI',
            'va_pani' => 'Virtual Account PANI',
            'va_cimb' => 'Virtual Account CIMB',
            'va_btpn' => 'Virtual Account BTPN',
            default => strtoupper(str_replace('_', ' ', $paymentMethod)),
        };
    }
}
