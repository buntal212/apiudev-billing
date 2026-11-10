<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\DanaEnvironmentSetting;
use App\Models\DanaPaymentMethodFee;
use App\Services\DanaFeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class DanaFeeController extends Controller
{
    public function __construct(private readonly DanaFeeService $danaFeeService) {}

    /**
     * Preview fee breakdown for a given method, amount, and merchant.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_method' => ['required', 'string', 'in:qris,dana_wallet,va_bri,va_pani,va_cimb,va_btpn'],
            'amount' => ['required', 'numeric', 'min:1'],
            'merchant_code' => ['nullable', 'string', 'max:60'],
            'environment' => ['nullable', 'string', 'in:sandbox,production'],
        ]);

        $environment = $validated['environment'] ?? $request->query('environment');
        $merchantCode = $validated['merchant_code'] ?? DanaEnvironmentSetting::getPrimaryMerchantCode($environment ?? 'sandbox');
        $paymentMethod = $validated['payment_method'];
        $amount = (float) $validated['amount'];

        $feeData = $this->danaFeeService->calculateFee($merchantCode, $paymentMethod, $amount, $environment);

        return response()->json([
            'status' => 'success',
            'data' => $feeData,
        ]);
    }

    /**
     * List current fee settings for DANA methods.
     */
    public function index(Request $request): JsonResponse
    {
        $environment = $request->query('environment');
        $merchantCode = $request->query('merchant_code') ?: DanaEnvironmentSetting::getPrimaryMerchantCode($environment ?? 'sandbox');
        $methods = ['qris', 'dana_wallet', 'va_bri', 'va_pani', 'va_cimb', 'va_btpn'];
        $items = [];

        foreach ($methods as $method) {
            $items[] = $this->danaFeeService->calculateFee($merchantCode, $method, 100000, $environment);
        }

        return response()->json([
            'status' => 'success',
            'data' => $items,
        ]);
    }
}
