<?php

namespace App\Services;

use App\Models\DanaEnvironmentSetting;
use App\Models\DanaPaymentFeeSetting;
use App\Models\DanaPaymentMethodFee;
use App\Models\Invoice;
use Illuminate\Support\Facades\Schema;

class DanaFeeService
{
    /**
     * Official standard DANA and UDEV fee configurations.
     * QRIS: MDR 0.70% (Standard Bank Indonesia / DANA).
     * DANA Wallet: MDR 1.50% (Official DANA PG rate).
     * Virtual Accounts (BRI, CIMB, BTPN, PANI): Rp 4.000 per transaction.
     * UDEV platform fee: default Rp 0.00.
     *
     * @var array<string, array{dana: array{fee_value: float, fee_unit: string, notes: string}, udev: array{fee_value: float, fee_unit: string, notes: string}}>
     */
    public const array OfficialStandardFees = [
        'qris' => [
            'dana' => [
                'fee_value' => 0.70,
                'fee_unit' => DanaPaymentFeeSetting::FeeUnitPercent,
                'notes' => 'MDR QRIS Standar Bank Indonesia / DANA',
            ],
            'udev' => [
                'fee_value' => 0.00,
                'fee_unit' => DanaPaymentFeeSetting::FeeUnitRupiah,
                'notes' => 'Biaya Layanan UDEV Platform',
            ],
        ],
        'dana_wallet' => [
            'dana' => [
                'fee_value' => 1.50,
                'fee_unit' => DanaPaymentFeeSetting::FeeUnitPercent,
                'notes' => 'MDR DANA Wallet PG',
            ],
            'udev' => [
                'fee_value' => 0.00,
                'fee_unit' => DanaPaymentFeeSetting::FeeUnitRupiah,
                'notes' => 'Biaya Layanan UDEV Platform',
            ],
        ],
        'va_bri' => [
            'dana' => [
                'fee_value' => 4000.00,
                'fee_unit' => DanaPaymentFeeSetting::FeeUnitRupiah,
                'notes' => 'Biaya Transaksi Virtual Account BRI',
            ],
            'udev' => [
                'fee_value' => 0.00,
                'fee_unit' => DanaPaymentFeeSetting::FeeUnitRupiah,
                'notes' => 'Biaya Layanan UDEV Platform',
            ],
        ],
        'va_cimb' => [
            'dana' => [
                'fee_value' => 4000.00,
                'fee_unit' => DanaPaymentFeeSetting::FeeUnitRupiah,
                'notes' => 'Biaya Transaksi Virtual Account CIMB',
            ],
            'udev' => [
                'fee_value' => 0.00,
                'fee_unit' => DanaPaymentFeeSetting::FeeUnitRupiah,
                'notes' => 'Biaya Layanan UDEV Platform',
            ],
        ],
        'va_btpn' => [
            'dana' => [
                'fee_value' => 4000.00,
                'fee_unit' => DanaPaymentFeeSetting::FeeUnitRupiah,
                'notes' => 'Biaya Transaksi Virtual Account BTPN',
            ],
            'udev' => [
                'fee_value' => 0.00,
                'fee_unit' => DanaPaymentFeeSetting::FeeUnitRupiah,
                'notes' => 'Biaya Layanan UDEV Platform',
            ],
        ],
        'va_pani' => [
            'dana' => [
                'fee_value' => 4000.00,
                'fee_unit' => DanaPaymentFeeSetting::FeeUnitRupiah,
                'notes' => 'Biaya Transaksi Virtual Account PANI',
            ],
            'udev' => [
                'fee_value' => 0.00,
                'fee_unit' => DanaPaymentFeeSetting::FeeUnitRupiah,
                'notes' => 'Biaya Layanan UDEV Platform',
            ],
        ],
    ];

    /**
     * Default standard payment method fee definitions for DANA (legacy compatibility).
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
     * Ensure official standard fee settings exist in database.
     */
    public function ensureDefaultSettings(?string $environment = null): void
    {
        if (! Schema::hasTable('dana_payment_fee_settings')) {
            return;
        }

        $envs = $environment ? [$environment] : ['sandbox', 'production'];

        foreach ($envs as $env) {
            foreach (self::OfficialStandardFees as $method => $feeTypes) {
                foreach ($feeTypes as $type => $def) {
                    DanaPaymentFeeSetting::query()->firstOrCreate(
                        [
                            'application_id' => null,
                            'environment' => $env,
                            'payment_method' => $method,
                            'fee_type' => $type,
                        ],
                        [
                            'fee_value' => $def['fee_value'],
                            'fee_unit' => $def['fee_unit'],
                            'is_active' => true,
                            'notes' => $def['notes'] ?? null,
                        ]
                    );
                }
            }
        }
    }

    /**
     * Calculate all fee components for a given subtotal, merchant, method, and application.
     *
     * @return array<string, mixed>
     */
    public function calculateFee(
        string $merchantCode,
        string $paymentMethod,
        float|int|string $subtotal,
        ?string $environment = null,
        ?int $applicationId = null,
    ): array {
        $subtotalFloat = (float) $subtotal;
        $env = $environment ?: (string) config('services.dana_gateway.environment', 'sandbox');

        // Check if modern fee settings table exists and has active rules
        $hasFeeSettingsTable = Schema::hasTable('dana_payment_fee_settings');
        $danaSetting = null;
        $udevSetting = null;

        if ($hasFeeSettingsTable) {
            // 1. Resolve DANA Fee Setting (app-specific first, then global default)
            $danaQuery = DanaPaymentFeeSetting::query()
                ->where('environment', $env)
                ->where('payment_method', $paymentMethod)
                ->where('fee_type', DanaPaymentFeeSetting::FeeTypeDana)
                ->where('is_active', true);

            if ($applicationId !== null) {
                $danaSetting = (clone $danaQuery)->where('application_id', $applicationId)->first();
            }

            if ($danaSetting === null) {
                $danaSetting = (clone $danaQuery)->whereNull('application_id')->first();
            }

            // 2. Resolve UDEV Fee Setting (app-specific first, then global default)
            $udevQuery = DanaPaymentFeeSetting::query()
                ->where('environment', $env)
                ->where('payment_method', $paymentMethod)
                ->where('fee_type', DanaPaymentFeeSetting::FeeTypeUdev)
                ->where('is_active', true);

            if ($applicationId !== null) {
                $udevSetting = (clone $udevQuery)->where('application_id', $applicationId)->first();
            }

            if ($udevSetting === null) {
                $udevSetting = (clone $udevQuery)->whereNull('application_id')->first();
            }
        }

        // If at least one active fee setting is found from dana_payment_fee_settings
        if ($danaSetting !== null || $udevSetting !== null) {
            $danaFeeAmount = $danaSetting !== null ? $danaSetting->computeAmount($subtotalFloat) : 0.0;
            $danaFeeValue = $danaSetting !== null ? (float) $danaSetting->fee_value : 0.0;
            $danaFeeUnit = $danaSetting !== null ? $danaSetting->fee_unit : DanaPaymentFeeSetting::FeeUnitRupiah;

            $udevFeeAmount = $udevSetting !== null ? $udevSetting->computeAmount($subtotalFloat) : 0.0;
            $udevFeeValue = $udevSetting !== null ? (float) $udevSetting->fee_value : 0.0;
            $udevFeeUnit = $udevSetting !== null ? $udevSetting->fee_unit : DanaPaymentFeeSetting::FeeUnitRupiah;

            $totalCustomerFee = (float) round($danaFeeAmount + $udevFeeAmount);
            $totalPayable = (float) round($subtotalFloat + $totalCustomerFee);

            return [
                'payment_method' => $paymentMethod,
                'payment_method_name' => $this->resolvePaymentMethodLabel($paymentMethod),
                'environment' => $env,
                'application_id' => $applicationId,
                'subtotal_amount' => $subtotalFloat,
                'base_amount' => $subtotalFloat,
                'dana_fee' => $danaFeeAmount,
                'dana_fee_amount' => $danaFeeAmount,
                'dana_fee_value' => $danaFeeValue,
                'dana_fee_unit' => $danaFeeUnit,
                'udev_fee' => $udevFeeAmount,
                'udev_fee_amount' => $udevFeeAmount,
                'udev_fee_value' => $udevFeeValue,
                'udev_fee_unit' => $udevFeeUnit,
                'total_fee' => $totalCustomerFee,
                'customer_fee' => $totalCustomerFee,
                'fee_amount' => $totalCustomerFee,
                'fee_rate' => $danaFeeUnit === DanaPaymentFeeSetting::FeeUnitPercent ? $danaFeeValue : 0.0,
                'total_amount' => $totalPayable,
                'customer_fee_policy' => 'customer_surcharge',
                'merchant_fee_type' => $danaFeeUnit,
                'merchant_fee_rate' => $danaFeeValue,
                'merchant_fixed_amount' => $danaFeeUnit === DanaPaymentFeeSetting::FeeUnitRupiah ? $danaFeeValue : 0.0,
                'estimated_merchant_fee' => $danaFeeAmount,
                'estimated_net_settlement' => $subtotalFloat, // Hak pemilik aplikasi tetap utuh sebesar tagihan pokok
                'rule_id' => $danaSetting?->id ?? $udevSetting?->id,
                'dana_setting_id' => $danaSetting?->id,
                'udev_setting_id' => $udevSetting?->id,
            ];
        }

        // Fallback to legacy DanaPaymentMethodFee if table exists
        $rule = null;
        if (Schema::hasTable('dana_payment_method_fees')) {
            $hasEnvColumn = Schema::hasColumn('dana_payment_method_fees', 'environment');

            $query = DanaPaymentMethodFee::query()
                ->where('merchant_code', $merchantCode)
                ->where('payment_method', $paymentMethod);

            if ($hasEnvColumn) {
                $query->where('environment', $env);
            }

            $rule = $query->first();

            $primaryCode = DanaEnvironmentSetting::getPrimaryMerchantCode($env);
            if ($rule === null && $merchantCode !== $primaryCode) {
                $fallbackQuery = DanaPaymentMethodFee::query()
                    ->where('merchant_code', $primaryCode)
                    ->where('payment_method', $paymentMethod);

                if ($hasEnvColumn) {
                    $fallbackQuery->where('environment', $env);
                }

                $rule = $fallbackQuery->first();
            }

            if ($rule === null && $merchantCode !== 'udev-central' && $primaryCode !== 'udev-central') {
                $fallbackQuery = DanaPaymentMethodFee::query()
                    ->where('merchant_code', 'udev-central')
                    ->where('payment_method', $paymentMethod);

                if ($hasEnvColumn) {
                    $fallbackQuery->where('environment', $env);
                }

                $rule = $fallbackQuery->first();
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
            $configuredRates = config('services.dana_gateway.fees.'.$paymentMethod, []);
            $merchantFeeType = $configuredRates['merchant_type'] ?? $default['merchant_type'];
            $merchantFeeRate = (float) ($configuredRates['merchant_rate'] ?? $default['merchant_rate']);
            $merchantFixed = (float) ($configuredRates['merchant_fixed'] ?? $default['merchant_fixed']);
            $customerFee = 0.0;

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
            'environment' => $env,
            'application_id' => $applicationId,
            'customer_fee' => $customerFee,
            'fee_amount' => $customerFee,
            'fee_rate' => 0.0,
            'dana_fee' => 0.0,
            'dana_fee_amount' => 0.0,
            'dana_fee_value' => 0.0,
            'dana_fee_unit' => DanaPaymentFeeSetting::FeeUnitRupiah,
            'udev_fee' => 0.0,
            'udev_fee_amount' => 0.0,
            'udev_fee_value' => 0.0,
            'udev_fee_unit' => DanaPaymentFeeSetting::FeeUnitRupiah,
            'total_fee' => $customerFee,
            'subtotal_amount' => $subtotalFloat,
            'base_amount' => $subtotalFloat,
            'total_amount' => $totalPayable,
            'customer_fee_policy' => $policy,
            'merchant_fee_type' => $merchantFeeType,
            'merchant_fee_rate' => $merchantFeeRate,
            'merchant_fixed_amount' => $merchantFixed,
            'estimated_merchant_fee' => $merchantFee,
            'estimated_net_settlement' => $netSettlement,
            'rule_id' => $ruleId,
            'dana_setting_id' => null,
            'udev_setting_id' => null,
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
        ?string $environment = null,
        ?int $applicationId = null,
    ): array {
        $env = $environment ?: (string) config('services.dana_gateway.environment', 'sandbox');
        $merchant = $merchantCode ?: DanaEnvironmentSetting::getPrimaryMerchantCode($env);
        $subtotal = (float) $invoice->getRawOriginal('amount');
        $appId = $applicationId ?? $invoice->plan?->application_id ?? $invoice->customer?->application_id;

        $calc = $this->calculateFee($merchant, $paymentMethod, $subtotal, $env, $appId);

        $metadata = is_array($invoice->metadata) ? $invoice->metadata : [];
        $basePrice = (float) ($metadata['normal_price'] ?? $subtotal);
        $discountAmount = (float) ($metadata['referral_discount'] ?? 0.0);

        return [
            'package_name' => $invoice->plan?->name ?? 'Paket Langganan',
            'base_price' => $basePrice,
            'discount_amount' => $discountAmount,
            'subtotal_amount' => $subtotal,
            'base_amount' => $subtotal,
            'payment_method' => $paymentMethod,
            'payment_method_label' => $calc['payment_method_name'],
            'provider' => 'dana',
            'environment' => $env,
            'application_id' => $appId,
            'dana_fee' => $calc['dana_fee_amount'] ?? 0.0,
            'dana_fee_amount' => $calc['dana_fee_amount'] ?? 0.0,
            'dana_fee_value' => $calc['dana_fee_value'] ?? 0.0,
            'dana_fee_unit' => $calc['dana_fee_unit'] ?? DanaPaymentFeeSetting::FeeUnitRupiah,
            'udev_fee' => $calc['udev_fee_amount'] ?? 0.0,
            'udev_fee_amount' => $calc['udev_fee_amount'] ?? 0.0,
            'udev_fee_value' => $calc['udev_fee_value'] ?? 0.0,
            'udev_fee_unit' => $calc['udev_fee_unit'] ?? DanaPaymentFeeSetting::FeeUnitRupiah,
            'total_fee' => $calc['total_fee'] ?? 0.0,
            'customer_fee' => $calc['customer_fee'] ?? 0.0,
            'fee_amount' => $calc['fee_amount'] ?? 0.0,
            'fee_rate' => $calc['fee_rate'] ?? 0.0,
            'fee_type' => ($calc['dana_fee_unit'] ?? null) === DanaPaymentFeeSetting::FeeUnitPercent ? 'percent' : 'fixed',
            'total_amount' => $calc['total_amount'],
            'customer_fee_policy' => $calc['customer_fee_policy'],
            'merchant_fee_type' => $calc['merchant_fee_type'],
            'merchant_fee_rate' => $calc['merchant_fee_rate'],
            'merchant_fixed_amount' => $calc['merchant_fixed_amount'],
            'estimated_merchant_fee' => $calc['estimated_merchant_fee'],
            'estimated_net_settlement' => $calc['estimated_net_settlement'], // Hak pemilik aplikasi sebesar tagihan pokok
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
