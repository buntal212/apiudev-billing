<?php

namespace App\Services;

use App\Models\DanaEnvironmentSetting;
use App\Models\DanaPaymentFeeSetting;
use App\Models\DanaPaymentMethodFee;
use App\Models\Invoice;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DanaFeeService
{
    /**
     * Official constants for UDEV Billing QRIS tiered MDR (Bank Indonesia / DANA).
     * Category UMI (Usaha Mikro):
     * - Nominal <= Rp 500.000: MDR 0.00%
     * - Nominal > Rp 500.000: MDR 0.30% dari seluruh nominal transaksi
     */
    public const float QrisUmiThreshold = 500000.0;

    public const float QrisUmiMdrRate = 0.30;

    public const string DefaultMerchantCategory = 'UMI';

    public const string DefaultShopId = 'UDEV001';

    /**
     * Official standard DANA and UDEV fee configurations based on the Udumbara Contract.
     * QRIS: Tiered UMI (<=500k 0%, >500k 0.30%) / Standard 0.70%.
     * DANA Wallet: MDR 1.30% (Official Udumbara Contract rate).
     * Virtual Accounts (BRI, Mandiri, BNI, CIMB, Panin): Rp 1.500 per transaction (Contract rate).
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
                'fee_value' => 1.30,
                'fee_unit' => DanaPaymentFeeSetting::FeeUnitPercent,
                'notes' => 'MDR DANA Wallet PG (Kontrak Udumbara 1,3%)',
            ],
            'udev' => [
                'fee_value' => 0.00,
                'fee_unit' => DanaPaymentFeeSetting::FeeUnitRupiah,
                'notes' => 'Biaya Layanan UDEV Platform',
            ],
        ],
        'va_bri' => [
            'dana' => [
                'fee_value' => 1500.00,
                'fee_unit' => DanaPaymentFeeSetting::FeeUnitRupiah,
                'notes' => 'Biaya Transaksi Virtual Account BRI (Kontrak Udumbara Rp1.500)',
            ],
            'udev' => [
                'fee_value' => 0.00,
                'fee_unit' => DanaPaymentFeeSetting::FeeUnitRupiah,
                'notes' => 'Biaya Layanan UDEV Platform',
            ],
        ],
        'va_mandiri' => [
            'dana' => [
                'fee_value' => 1500.00,
                'fee_unit' => DanaPaymentFeeSetting::FeeUnitRupiah,
                'notes' => 'Biaya Transaksi Virtual Account Mandiri (Kontrak Udumbara Rp1.500)',
            ],
            'udev' => [
                'fee_value' => 0.00,
                'fee_unit' => DanaPaymentFeeSetting::FeeUnitRupiah,
                'notes' => 'Biaya Layanan UDEV Platform',
            ],
        ],
        'va_bni' => [
            'dana' => [
                'fee_value' => 1500.00,
                'fee_unit' => DanaPaymentFeeSetting::FeeUnitRupiah,
                'notes' => 'Biaya Transaksi Virtual Account BNI (Kontrak Udumbara Rp1.500)',
            ],
            'udev' => [
                'fee_value' => 0.00,
                'fee_unit' => DanaPaymentFeeSetting::FeeUnitRupiah,
                'notes' => 'Biaya Layanan UDEV Platform',
            ],
        ],
        'va_cimb' => [
            'dana' => [
                'fee_value' => 1500.00,
                'fee_unit' => DanaPaymentFeeSetting::FeeUnitRupiah,
                'notes' => 'Biaya Transaksi Virtual Account CIMB (Kontrak Udumbara Rp1.500)',
            ],
            'udev' => [
                'fee_value' => 0.00,
                'fee_unit' => DanaPaymentFeeSetting::FeeUnitRupiah,
                'notes' => 'Biaya Layanan UDEV Platform',
            ],
        ],
        'va_pani' => [
            'dana' => [
                'fee_value' => 1500.00,
                'fee_unit' => DanaPaymentFeeSetting::FeeUnitRupiah,
                'notes' => 'Biaya Transaksi Virtual Account Panin (Kontrak Udumbara Rp1.500)',
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
            'merchant_rate' => 1.3000,
            'merchant_fixed' => 0.00,
            'customer_type' => DanaPaymentMethodFee::TypeFixed,
            'customer_rate' => 0.0000,
            'customer_fixed' => 0.00,
            'customer_policy' => 'absorbed_by_merchant',
        ],
        'va_bri' => [
            'name' => 'Virtual Account BRI',
            'merchant_type' => DanaPaymentMethodFee::TypeFixed,
            'merchant_rate' => 0.0000,
            'merchant_fixed' => 1500.00,
            'customer_type' => DanaPaymentMethodFee::TypeFixed,
            'customer_rate' => 0.0000,
            'customer_fixed' => 0.00,
            'customer_policy' => 'absorbed_by_merchant',
        ],
        'va_mandiri' => [
            'name' => 'Virtual Account Mandiri',
            'merchant_type' => DanaPaymentMethodFee::TypeFixed,
            'merchant_rate' => 0.0000,
            'merchant_fixed' => 1500.00,
            'customer_type' => DanaPaymentMethodFee::TypeFixed,
            'customer_rate' => 0.0000,
            'customer_fixed' => 0.00,
            'customer_policy' => 'absorbed_by_merchant',
        ],
        'va_bni' => [
            'name' => 'Virtual Account BNI',
            'merchant_type' => DanaPaymentMethodFee::TypeFixed,
            'merchant_rate' => 0.0000,
            'merchant_fixed' => 1500.00,
            'customer_type' => DanaPaymentMethodFee::TypeFixed,
            'customer_rate' => 0.0000,
            'customer_fixed' => 0.00,
            'customer_policy' => 'absorbed_by_merchant',
        ],
        'va_pani' => [
            'name' => 'Virtual Account Panin',
            'merchant_type' => DanaPaymentMethodFee::TypeFixed,
            'merchant_rate' => 0.0000,
            'merchant_fixed' => 1500.00,
            'customer_type' => DanaPaymentMethodFee::TypeFixed,
            'customer_rate' => 0.0000,
            'customer_fixed' => 0.00,
            'customer_policy' => 'absorbed_by_merchant',
        ],
        'va_cimb' => [
            'name' => 'Virtual Account CIMB',
            'merchant_type' => DanaPaymentMethodFee::TypeFixed,
            'merchant_rate' => 0.0000,
            'merchant_fixed' => 1500.00,
            'customer_type' => DanaPaymentMethodFee::TypeFixed,
            'customer_rate' => 0.0000,
            'customer_fixed' => 0.00,
            'customer_policy' => 'absorbed_by_merchant',
        ],
    ];

    /**
     * Ensure official standard fee settings exist in database for sandbox testing.
     * Production is NEVER auto-seeded.
     */
    public function ensureDefaultSettings(?string $environment = null): void
    {
        // Production must NEVER be auto-seeded
        if ($environment === 'production') {
            return;
        }

        if (! Schema::hasTable('dana_payment_fee_settings')) {
            return;
        }

        $env = 'sandbox';
        foreach (self::OfficialStandardFees as $method => $feeTypes) {
            foreach ($feeTypes as $type => $def) {
                DanaPaymentFeeSetting::query()->firstOrCreate(
                    [
                        'scope_key' => 'global',
                        'environment' => $env,
                        'payment_method' => $method,
                        'fee_type' => $type,
                    ],
                    [
                        'gateway_merchant_code' => null,
                        'application_id' => null,
                        'fee_value' => $def['fee_value'],
                        'fee_unit' => $def['fee_unit'],
                        'is_active' => true,
                        'notes' => $def['notes'] ?? null,
                    ]
                );
            }
        }
    }

    /**
     * Calculate automatic tiered QRIS MDR based on Bank Indonesia / DANA policy.
     * Category UMI (Usaha Mikro):
     * - Nominal <= Rp 500.000: MDR 0.00%
     * - Nominal > Rp 500.000: MDR 0.30% dari seluruh nominal transaksi
     *
     * @return array{
     *     rate: float,
     *     fee_amount: float,
     *     tier: string,
     *     tier_label: string,
     *     category: string,
     *     threshold: float
     * }
     */
    public function calculateAutomaticQrisMdr(float|int|string $subtotal, string $category = self::DefaultMerchantCategory): array
    {
        $amount = (float) $subtotal;
        $cat = strtoupper(trim($category));

        if ($cat === self::DefaultMerchantCategory) {
            if ($amount <= self::QrisUmiThreshold) {
                return [
                    'rate' => 0.0,
                    'fee_amount' => 0.0,
                    'tier' => 'tier_le_500k',
                    'tier_label' => '≤ Rp 500.000 (MDR 0%)',
                    'category' => $cat,
                    'threshold' => self::QrisUmiThreshold,
                ];
            }

            $rate = self::QrisUmiMdrRate;
            $fee = (float) round($amount * ($rate / 100));

            return [
                'rate' => $rate,
                'fee_amount' => $fee,
                'tier' => 'tier_gt_500k',
                'tier_label' => '> Rp 500.000 (MDR 0,3%)',
                'category' => $cat,
                'threshold' => self::QrisUmiThreshold,
            ];
        }

        // For non-UMI categories (e.g. UKE/UME/UBE standard 0.70%)
        $rate = 0.70;
        $fee = (float) round($amount * ($rate / 100));

        return [
            'rate' => $rate,
            'fee_amount' => $fee,
            'tier' => 'standard',
            'tier_label' => 'Standard (MDR 0,7%)',
            'category' => $cat,
            'threshold' => 0.0,
        ];
    }

    /**
     * Calculate all fee components for a given subtotal, merchant, method, and application.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function calculateFee(
        string $merchantCode,
        string $paymentMethod,
        float|int|string $subtotal,
        ?string $environment = null,
        ?int $applicationId = null,
        ?string $merchantCategory = null,
    ): array {
        $subtotalFloat = (float) $subtotal;
        $env = $environment ?: (string) config('services.dana_gateway.environment', 'sandbox');

        $trimmedMerchant = trim($merchantCode);
        if ($trimmedMerchant === '') {
            throw ValidationException::withMessages([
                'merchant_code' => [
                    "Merchant DANA tidak teridentifikasi (kode merchant kosong). Checkout dibatalkan (fail-closed).",
                ],
            ]);
        }

        $primaryMerchantCode = trim((string) DanaEnvironmentSetting::getPrimaryMerchantCode($env));
        $centralMerchantCodes = array_values(array_unique(array_filter([
            'udev-central',
            'MRC-0002',
            'udev-demo',
            $primaryMerchantCode,
            trim((string) config('services.dana_gateway.merchant_id')),
        ])));
        $isCentralMerchant = in_array($trimmedMerchant, $centralMerchantCodes, true);
        $isMerchantMandiri = ! $isCentralMerchant;
        $effectiveMerchantCategory = $merchantCategory ?: ($isCentralMerchant ? self::DefaultMerchantCategory : null);
        $shopId = $isCentralMerchant ? self::DefaultShopId : null;

        // Check if modern fee settings table exists and has active rules
        $hasFeeSettingsTable = Schema::hasTable('dana_payment_fee_settings');
        $danaSetting = null;
        $udevSetting = null;

        if ($hasFeeSettingsTable) {
            $resolveSetting = function (string $feeType) use ($env, $paymentMethod, $trimmedMerchant, $applicationId, $isMerchantMandiri): ?DanaPaymentFeeSetting {
                $baseQuery = DanaPaymentFeeSetting::query()
                    ->where('environment', $env)
                    ->where('payment_method', $paymentMethod)
                    ->where('fee_type', $feeType)
                    ->where('is_active', true);

                // 1. Most specific: application_id + gateway_merchant_code
                if ($applicationId !== null && $trimmedMerchant !== '') {
                    $setting = (clone $baseQuery)
                        ->where('application_id', $applicationId)
                        ->where('gateway_merchant_code', $trimmedMerchant)
                        ->first();
                    if ($setting !== null) {
                        return $setting;
                    }
                }

                // 2. Specific to gateway_merchant_code (where application_id IS NULL)
                if ($trimmedMerchant !== '') {
                    $setting = (clone $baseQuery)
                        ->where('gateway_merchant_code', $trimmedMerchant)
                        ->whereNull('application_id')
                        ->first();
                    if ($setting !== null) {
                        return $setting;
                    }
                }

                // 3. Specific to application_id (where gateway_merchant_code IS NULL)
                if ($applicationId !== null) {
                    $setting = (clone $baseQuery)
                        ->where('application_id', $applicationId)
                        ->whereNull('gateway_merchant_code')
                        ->first();
                    if ($setting !== null) {
                        return $setting;
                    }
                }

                // 4. Global default (both application_id and gateway_merchant_code are NULL)
                // Merchant mandiri WITHOUT valid fee MUST FAIL-CLOSED without fallback to UDEV Central!
                if ($isMerchantMandiri) {
                    return null;
                }

                return (clone $baseQuery)
                    ->whereNull('application_id')
                    ->whereNull('gateway_merchant_code')
                    ->first();
            };

            $danaSetting = $resolveSetting(DanaPaymentFeeSetting::FeeTypeDana);
            $udevSetting = $resolveSetting(DanaPaymentFeeSetting::FeeTypeUdev);
        }

        $isAutomaticQrisMdr = false;
        $qrisMdr = null;

        if ($paymentMethod === 'qris' && $isCentralMerchant) {
            // Automatic QRIS MDR applies for central merchant with category UMI when:
            // 1. No custom positive setting in DB ($danaSetting === null), OR
            // 2. Setting has fee_value 0.0 (temporary UAT zero fee), OR
            // 3. Category UMI is explicitly requested or active
            if ($danaSetting === null || (float) $danaSetting->fee_value === 0.0 || $merchantCategory === self::DefaultMerchantCategory) {
                $isAutomaticQrisMdr = true;
                $qrisMdr = $this->calculateAutomaticQrisMdr($subtotalFloat, $effectiveMerchantCategory ?? self::DefaultMerchantCategory);
            }
        }

        // FAIL-CLOSED CHECK 1: Merchant mandiri without valid fee configuration
        if ($isMerchantMandiri && $danaSetting === null) {
            throw ValidationException::withMessages([
                'payment_method' => [
                    "Tarif biaya DANA untuk merchant mandiri '{$merchantCode}' pada metode '{$paymentMethod}' ({$env}) belum dikonfigurasi. Checkout dibatalkan (fail-closed).",
                ],
            ]);
        }

        // FAIL-CLOSED CHECK 2: Production environment without fee configuration
        // QRIS for UDEV Central with category UMI has valid automatic MDR (0% / 0.3%)
        if ($env === 'production' && $danaSetting === null && ! $isAutomaticQrisMdr) {
            throw ValidationException::withMessages([
                'payment_method' => [
                    "Tarif biaya DANA Production untuk metode '{$paymentMethod}' belum dikonfigurasi. Checkout dibatalkan (fail-closed).",
                ],
            ]);
        }

        // If modern fee settings table exists, resolve fees (or zero fee if no active rule configured)
        if ($hasFeeSettingsTable) {
            if ($isAutomaticQrisMdr) {
                $danaFeeAmount = (float) $qrisMdr['fee_amount'];
                $danaFeeValue = (float) $qrisMdr['rate'];
                $danaFeeUnit = DanaPaymentFeeSetting::FeeUnitPercent;

                $udevFeeAmount = $udevSetting !== null ? $udevSetting->computeAmount($subtotalFloat) : 0.0;
                $udevFeeValue = $udevSetting !== null ? (float) $udevSetting->fee_value : 0.0;
                $udevFeeUnit = $udevSetting !== null ? $udevSetting->fee_unit : DanaPaymentFeeSetting::FeeUnitRupiah;

                // Kontrak DANA: Zero surcharge pelanggan (bebas biaya admin/MDR).
                // Seluruh biaya gateway DANA ditanggung merchant.
                // Biaya platform UDEV dicatat internal untuk pelaporan platform, tidak ditagihkan ke pelanggan DANA.
                $totalCustomerFee = 0.0;
                $totalPayable = $subtotalFloat;
                $customerFeePolicy = 'absorbed_by_merchant';
                $estimatedMerchantFee = $danaFeeAmount;
                $estimatedNetSettlement = max(0.0, (float) round($subtotalFloat - $danaFeeAmount));
                $isTemporaryZeroFee = $danaSetting !== null && (float) $danaSetting->fee_value === 0.0;
            } else {
                $danaFeeAmount = $danaSetting !== null ? $danaSetting->computeAmount($subtotalFloat) : 0.0;
                $danaFeeValue = $danaSetting !== null ? (float) $danaSetting->fee_value : 0.0;
                $danaFeeUnit = $danaSetting !== null ? $danaSetting->fee_unit : DanaPaymentFeeSetting::FeeUnitRupiah;

                $udevFeeAmount = $udevSetting !== null ? $udevSetting->computeAmount($subtotalFloat) : 0.0;
                $udevFeeValue = $udevSetting !== null ? (float) $udevSetting->fee_value : 0.0;
                $udevFeeUnit = $udevSetting !== null ? $udevSetting->fee_unit : DanaPaymentFeeSetting::FeeUnitRupiah;

                // Kontrak DANA: Zero surcharge pelanggan (bebas biaya admin/MDR).
                // Seluruh biaya gateway DANA ditanggung merchant.
                // Biaya platform UDEV dicatat internal untuk pelaporan platform, tidak ditagihkan ke pelanggan DANA.
                $totalCustomerFee = 0.0;
                $totalPayable = $subtotalFloat;
                $customerFeePolicy = 'absorbed_by_merchant';
                $estimatedMerchantFee = $danaFeeAmount;
                $estimatedNetSettlement = max(0.0, (float) round($subtotalFloat - $danaFeeAmount));
                $isTemporaryZeroFee = $danaSetting !== null && (float) $danaSetting->fee_value === 0.0;
            }

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
                'dana_fee_details' => [
                    'fee_value' => $danaFeeValue,
                    'fee_unit' => $danaFeeUnit,
                ],
                'udev_fee' => $udevFeeAmount,
                'udev_fee_amount' => $udevFeeAmount,
                'udev_fee_value' => $udevFeeValue,
                'udev_fee_unit' => $udevFeeUnit,
                'udev_fee_details' => [
                    'fee_value' => $udevFeeValue,
                    'fee_unit' => $udevFeeUnit,
                ],
                'total_fee' => $totalCustomerFee,
                'customer_fee' => $totalCustomerFee,
                'fee_amount' => $totalCustomerFee,
                'fee_rate' => $danaFeeUnit === DanaPaymentFeeSetting::FeeUnitPercent ? $danaFeeValue : 0.0,
                'total_amount' => $totalPayable,
                'customer_fee_policy' => $customerFeePolicy,
                'merchant_fee_type' => $danaFeeUnit,
                'merchant_fee_rate' => $danaFeeValue,
                'merchant_fixed_amount' => $danaFeeUnit === DanaPaymentFeeSetting::FeeUnitRupiah ? $danaFeeValue : 0.0,
                'estimated_merchant_fee' => $estimatedMerchantFee,
                'estimated_gateway_fee' => $danaFeeAmount,
                'estimated_net_settlement' => $estimatedNetSettlement,
                'actual_gateway_fee' => null,
                'actual_settlement' => null,
                'settlement_status' => 'estimated',
                'is_temporary_zero_fee' => $isTemporaryZeroFee,
                'rule_id' => $danaSetting?->id ?? $udevSetting?->id,
                'dana_setting_id' => $danaSetting?->id,
                'udev_setting_id' => $udevSetting?->id,
                'merchant_category' => $effectiveMerchantCategory,
                'shop_id' => $shopId,
                'is_automatic_qris_mdr' => $isAutomaticQrisMdr,
                'qris_mdr_tier' => $isAutomaticQrisMdr ? ($qrisMdr['tier'] ?? null) : null,
                'qris_mdr_tier_label' => $isAutomaticQrisMdr ? ($qrisMdr['tier_label'] ?? null) : null,
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
            $customerFee = 0.0;
            $merchantFee = $rule->calculateMerchantFee($subtotalFloat);
            $methodName = $rule->payment_method_name;
            $merchantFeeType = $rule->merchant_fee_type;
            $merchantFeeRate = (float) $rule->merchant_fee_rate;
            $merchantFixed = (float) $rule->merchant_fixed_amount;
            $policy = 'absorbed_by_merchant';
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
            $policy = 'absorbed_by_merchant';
            $ruleId = null;
        }

        $totalPayable = $subtotalFloat;
        $netSettlement = max(0.0, (float) round($subtotalFloat - $merchantFee));

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
            'estimated_gateway_fee' => $merchantFee,
            'estimated_net_settlement' => $netSettlement,
            'actual_gateway_fee' => null,
            'actual_settlement' => null,
            'settlement_status' => 'estimated',
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
            'merchant_code' => $merchant,
            'gateway_merchant_code' => $merchant,
            'shop_id' => $calc['shop_id'] ?? null,
            'merchant_category' => $calc['merchant_category'] ?? null,
            'is_automatic_qris_mdr' => $calc['is_automatic_qris_mdr'] ?? false,
            'qris_mdr_tier' => $calc['qris_mdr_tier'] ?? null,
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
            'estimated_gateway_fee' => $calc['estimated_gateway_fee'] ?? $calc['estimated_merchant_fee'],
            'estimated_net_settlement' => $calc['estimated_net_settlement'], // Estimasi pelunasan bersih setelah dipotong biaya gateway DANA
            'actual_gateway_fee' => $calc['actual_gateway_fee'] ?? null,
            'actual_settlement' => $calc['actual_settlement'] ?? null,
            'settlement_status' => $calc['settlement_status'] ?? 'estimated',
            'is_temporary_zero_fee' => (bool) ($calc['is_temporary_zero_fee'] ?? false),
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
            'va_mandiri' => 'Virtual Account Mandiri',
            'va_bni' => 'Virtual Account BNI',
            'va_pani' => 'Virtual Account Panin',
            'va_cimb' => 'Virtual Account CIMB',
            'va_btpn' => 'Virtual Account BTPN',
            default => strtoupper(str_replace('_', ' ', $paymentMethod)),
        };
    }
}
