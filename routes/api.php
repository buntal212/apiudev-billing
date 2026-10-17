<?php

use App\Http\Controllers\Api\V1\Admin\ApplicationController;
use App\Http\Controllers\Api\V1\Admin\ApplicationCustomerController;
use App\Http\Controllers\Api\V1\Admin\ApplicationPaymentGatewayController;
use App\Http\Controllers\Api\V1\Admin\DanaGatewayMerchantProvisioningController;
use App\Http\Controllers\Api\V1\Admin\InvoiceCheckoutController;
use App\Http\Controllers\Api\V1\Admin\InvoiceController;
use App\Http\Controllers\Api\V1\Admin\PaymentAttemptController;
use App\Http\Controllers\Api\V1\Admin\SubscriptionController;
use App\Http\Controllers\Api\V1\Admin\SubscriptionPlanController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/auth')->group(function (): void {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:admin-login');

    Route::middleware(['auth:sanctum', 'abilities:admin:access', 'admin'])->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::prefix('v1/admin')
    ->middleware(['auth:sanctum', 'abilities:admin:access', 'admin'])
    ->group(function (): void {
        Route::apiResource('applications', ApplicationController::class)
            ->only(['index', 'store', 'show', 'update']);
        Route::get('payment-gateways/merchants', [ApplicationPaymentGatewayController::class, 'merchants']);
        Route::apiResource('payment-gateways', ApplicationPaymentGatewayController::class)
            ->only(['index', 'store', 'update']);
        Route::prefix('dana-gateway-merchants')->middleware('throttle:admin-dana-gateway-merchants')->group(function (): void {
            Route::get('/', [DanaGatewayMerchantProvisioningController::class, 'index']);
            Route::post('/', [DanaGatewayMerchantProvisioningController::class, 'store']);
            Route::get('{merchantId}/readiness', [DanaGatewayMerchantProvisioningController::class, 'readiness'])->whereNumber('merchantId');
            Route::get('{merchantId}/dana-configuration', [DanaGatewayMerchantProvisioningController::class, 'storedDanaConfiguration'])->whereNumber('merchantId');
            Route::put('{merchantId}/dana-configuration', [DanaGatewayMerchantProvisioningController::class, 'configureDanaSandbox'])->whereNumber('merchantId');
            Route::get('{merchantId}/api-keys', [DanaGatewayMerchantProvisioningController::class, 'apiKeys'])->whereNumber('merchantId');
            Route::post('{merchantId}/api-keys/issue', [DanaGatewayMerchantProvisioningController::class, 'issueApiKey'])->whereNumber('merchantId');
            Route::get('{merchantId}/api-key-issuances', [DanaGatewayMerchantProvisioningController::class, 'issuances'])->whereNumber('merchantId');
            Route::get('{merchantId}/api-key-issuances/{issuanceId}', [DanaGatewayMerchantProvisioningController::class, 'issuanceStatus'])->whereNumber(['merchantId', 'issuanceId']);
            Route::post('{merchantId}/api-key-issuances/{issuanceId}/cancel', [DanaGatewayMerchantProvisioningController::class, 'cancelIssuance'])->whereNumber(['merchantId', 'issuanceId']);
            Route::delete('{merchantId}/api-keys/{apiKeyId}', [DanaGatewayMerchantProvisioningController::class, 'revokeApiKey'])
                ->whereNumber(['merchantId', 'apiKeyId']);
            Route::get('{merchantId}', [DanaGatewayMerchantProvisioningController::class, 'show'])->whereNumber('merchantId');
        });
        Route::apiResource('application-customers', ApplicationCustomerController::class)
            ->only(['index', 'store', 'show', 'update']);
        Route::apiResource('subscription-plans', SubscriptionPlanController::class)
            ->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::apiResource('subscriptions', SubscriptionController::class)
            ->only(['index', 'show']);
        Route::apiResource('invoices', InvoiceController::class)
            ->only(['index', 'store', 'show']);
        Route::post('invoices/{invoice}/checkout', [InvoiceCheckoutController::class, 'store']);
        Route::apiResource('payment-attempts', PaymentAttemptController::class)
            ->only(['index', 'show']);
    });
