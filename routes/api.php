<?php

use App\Http\Controllers\Api\V1\Admin\ApplicationController;
use App\Http\Controllers\Api\V1\Admin\ApplicationCustomerController;
use App\Http\Controllers\Api\V1\Admin\ApplicationGlobalIntegrationTokenController;
use App\Http\Controllers\Api\V1\Admin\ApplicationIntegrationAuditController;
use App\Http\Controllers\Api\V1\Admin\ApplicationIntegrationTestAuditController;
use App\Http\Controllers\Api\V1\Admin\ApplicationIntegrationTestController;
use App\Http\Controllers\Api\V1\Admin\ApplicationIntegrationTokenController;
use App\Http\Controllers\Api\V1\Admin\ApplicationPaymentGatewayController;
use App\Http\Controllers\Api\V1\Admin\BillingGatewayTransactionController;
use App\Http\Controllers\Api\V1\Admin\DanaGatewayMerchantProvisioningController;
use App\Http\Controllers\Api\V1\Admin\InvoiceCheckoutController;
use App\Http\Controllers\Api\V1\Admin\InvoiceController;
use App\Http\Controllers\Api\V1\Admin\PartnerController;
use App\Http\Controllers\Api\V1\Admin\PaymentAttemptController;
use App\Http\Controllers\Api\V1\Admin\ReferralCodeController;
use App\Http\Controllers\Api\V1\Admin\SubscriptionController;
use App\Http\Controllers\Api\V1\Admin\SubscriptionPlanController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Integration\IntegrationBillingController;
use App\Http\Controllers\Api\V1\Integration\IntegrationCustomerController;
use App\Http\Controllers\Api\V1\Integration\IntegrationReferralController;
use App\Http\Controllers\Api\V1\Integration\IntegrationSubscriptionPlanController;
use App\Http\Controllers\Api\V1\PaymentResultController;
use Illuminate\Support\Facades\Route;

Route::get('v1/payment-results/{reference}', [PaymentResultController::class, 'show'])
    ->whereUlid('reference')
    ->middleware('throttle:payment-result');

Route::prefix('v1/auth')->group(function (): void {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:admin-login');

    Route::middleware(['auth:sanctum', 'abilities:admin:access', 'admin'])->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::prefix('v1/integrations')
    ->middleware(['integration.auth', 'integration.global', 'throttle:integration-api'])
    ->group(function (): void {
        Route::get('subscription-plans', [IntegrationSubscriptionPlanController::class, 'index']);
        Route::post('customers/{externalId}/invoice-preview', [IntegrationBillingController::class, 'preview'])
            ->where('externalId', '[A-Za-z0-9._:-]{1,100}');
        Route::post('customers/{externalId}/invoices', [IntegrationBillingController::class, 'store'])
            ->where('externalId', '[A-Za-z0-9._:-]{1,100}');
        Route::get('customers/{externalId}/transactions', [IntegrationBillingController::class, 'transactions'])
            ->where('externalId', '[A-Za-z0-9._:-]{1,100}');
        Route::get('invoices/{invoiceNumber}', [IntegrationBillingController::class, 'show'])
            ->where('invoiceNumber', '[A-Za-z0-9-]{1,100}');
        Route::post('invoices/{invoiceNumber}/checkout', [IntegrationBillingController::class, 'checkout'])
            ->where('invoiceNumber', '[A-Za-z0-9-]{1,100}');
        Route::post('customers', [IntegrationCustomerController::class, 'store']);
        Route::get('customers/{externalId}', [IntegrationCustomerController::class, 'show'])
            ->where('externalId', '[A-Za-z0-9._:-]{1,100}');
        Route::patch('customers/{externalId}', [IntegrationCustomerController::class, 'update'])
            ->where('externalId', '[A-Za-z0-9._:-]{1,100}');
        Route::post('referrals/validate', [IntegrationReferralController::class, 'validate']);
        Route::post('customers/{externalId}/referral', [IntegrationReferralController::class, 'attach'])
            ->where('externalId', '[A-Za-z0-9._:-]{1,100}');
        Route::get('customers/{externalId}/subscription', [IntegrationReferralController::class, 'subscription'])
            ->where('externalId', '[A-Za-z0-9._:-]{1,100}');
    });

Route::prefix('v1/admin')
    ->middleware(['auth:sanctum', 'abilities:admin:access', 'admin'])
    ->group(function (): void {
        Route::apiResource('partners', PartnerController::class)
            ->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::get('referral-codes/{referralCode}/usages', [ReferralCodeController::class, 'usages']);
        Route::apiResource('referral-codes', ReferralCodeController::class)
            ->only(['index', 'store', 'show', 'update']);
        Route::apiResource('applications', ApplicationController::class)
            ->only(['index', 'store', 'show', 'update']);
        Route::get('applications/{application}/integration-tokens', [ApplicationIntegrationTokenController::class, 'index'])
            ->middleware('throttle:admin-dana-gateway-read');
        Route::get('applications/{application}/integration-audits', [ApplicationIntegrationAuditController::class, 'index'])
            ->middleware('throttle:admin-dana-gateway-read');
        Route::get('applications/{application}/integration-tests/audits', [ApplicationIntegrationTestAuditController::class, 'index'])
            ->middleware('throttle:admin-integration-test');
        Route::post('applications/{application}/integration-tests/customers', [ApplicationIntegrationTestController::class, 'store'])
            ->middleware('throttle:admin-integration-test');
        Route::get('applications/{application}/integration-tests/customers/{externalId}', [ApplicationIntegrationTestController::class, 'show'])
            ->where('externalId', '[A-Za-z0-9._:-]{1,100}')
            ->middleware('throttle:admin-integration-test');
        Route::patch('applications/{application}/integration-tests/customers/{externalId}', [ApplicationIntegrationTestController::class, 'update'])
            ->where('externalId', '[A-Za-z0-9._:-]{1,100}')
            ->middleware('throttle:admin-integration-test');
        Route::post('applications/{application}/integration-tokens', [ApplicationIntegrationTokenController::class, 'store'])
            ->middleware('throttle:admin-dana-gateway-write');
        Route::delete('applications/{application}/integration-tokens/{integrationToken}', [ApplicationIntegrationTokenController::class, 'destroy'])
            ->middleware('throttle:admin-dana-gateway-write');
        Route::get('applications/{application}/global-integration-token', [ApplicationGlobalIntegrationTokenController::class, 'show']);
        Route::post('applications/{application}/global-integration-token', [ApplicationGlobalIntegrationTokenController::class, 'store'])
            ->middleware('throttle:admin-dana-gateway-write');
        Route::post('applications/{application}/global-integration-token/rotate', [ApplicationGlobalIntegrationTokenController::class, 'rotate'])
            ->middleware('throttle:admin-dana-gateway-write');
        Route::delete('applications/{application}/global-integration-token', [ApplicationGlobalIntegrationTokenController::class, 'destroy'])
            ->middleware('throttle:admin-dana-gateway-write');
        Route::get('payment-gateways/merchants', [ApplicationPaymentGatewayController::class, 'merchants']);
        Route::get('payment-gateway/billing-transactions', [BillingGatewayTransactionController::class, 'index'])
            ->middleware('throttle:admin-dana-gateway-read');
        Route::get('payment-gateway/billing-transactions/{paymentAttempt}', [BillingGatewayTransactionController::class, 'show'])
            ->middleware('throttle:admin-dana-gateway-read');
        Route::apiResource('payment-gateways', ApplicationPaymentGatewayController::class)
            ->only(['index', 'store', 'update']);
        Route::prefix('dana-gateway-merchants')->group(function (): void {
            Route::middleware('throttle:admin-dana-gateway-read')->group(function (): void {
                Route::get('/', [DanaGatewayMerchantProvisioningController::class, 'index']);
                Route::get('{merchantId}/readiness', [DanaGatewayMerchantProvisioningController::class, 'readiness'])->whereNumber('merchantId');
                Route::get('{merchantId}/dana-configuration', [DanaGatewayMerchantProvisioningController::class, 'storedDanaConfiguration'])->whereNumber('merchantId');
                Route::get('{merchantId}/sandbox-tester/validation', [DanaGatewayMerchantProvisioningController::class, 'validateDanaSandboxTester'])->whereNumber('merchantId');
                Route::get('{merchantId}/sandbox-tester/transactions', [DanaGatewayMerchantProvisioningController::class, 'danaSandboxTestHistory'])->whereNumber('merchantId');
                Route::get('{merchantId}/sandbox-tester/transactions/{paymentId}/status', [DanaGatewayMerchantProvisioningController::class, 'danaSandboxTestTransactionStatus'])->whereNumber('merchantId')->whereUlid('paymentId');
                Route::get('{merchantId}/sandbox-tester/transactions/{paymentId}/dana-status', [DanaGatewayMerchantProvisioningController::class, 'queryDanaSandboxPaymentStatus'])->whereNumber('merchantId')->whereUlid('paymentId');
                Route::get('{merchantId}/api-keys', [DanaGatewayMerchantProvisioningController::class, 'apiKeys'])->whereNumber('merchantId');
                Route::get('{merchantId}/api-key-issuances', [DanaGatewayMerchantProvisioningController::class, 'issuances'])->whereNumber('merchantId');
                Route::get('{merchantId}/api-key-issuances/{issuanceId}', [DanaGatewayMerchantProvisioningController::class, 'issuanceStatus'])->whereNumber(['merchantId', 'issuanceId']);
                Route::get('{merchantId}', [DanaGatewayMerchantProvisioningController::class, 'show'])->whereNumber('merchantId');
            });
            Route::middleware('throttle:admin-sandbox-audit-read')->group(function (): void {
                Route::get('{merchantId}/sandbox-tester/payments', [DanaGatewayMerchantProvisioningController::class, 'danaSandboxPayments'])->whereNumber('merchantId');
                Route::get('{merchantId}/sandbox-tester/payments/summary', [DanaGatewayMerchantProvisioningController::class, 'danaSandboxPaymentSummary'])->whereNumber('merchantId');
                Route::get('{merchantId}/sandbox-tester/payments/{paymentId}', [DanaGatewayMerchantProvisioningController::class, 'danaSandboxPaymentDetail'])->whereNumber('merchantId')->whereUlid('paymentId');
                Route::get('{merchantId}/sandbox-tester/payments/{paymentId}/webhooks', [DanaGatewayMerchantProvisioningController::class, 'danaSandboxPaymentWebhooks'])->whereNumber('merchantId')->whereUlid('paymentId');
            });
            Route::middleware('throttle:admin-sandbox-payment-result')->group(function (): void {
                Route::get('sandbox-payment-results/{context}', [DanaGatewayMerchantProvisioningController::class, 'sandboxPaymentResult'])->where('context', '[A-Za-z0-9_-]{43}');
                Route::get('{merchantId}/sandbox-tester/transactions/{paymentId}/result', [DanaGatewayMerchantProvisioningController::class, 'sandboxPaymentResultFromHistory'])->whereNumber('merchantId')->whereUlid('paymentId');
            });
            Route::middleware('throttle:admin-dana-gateway-write')->group(function (): void {
                Route::post('/', [DanaGatewayMerchantProvisioningController::class, 'store']);
                Route::put('{merchantId}/dana-configuration', [DanaGatewayMerchantProvisioningController::class, 'configureDanaSandbox'])->whereNumber('merchantId');
                Route::post('{merchantId}/sandbox-tester/transactions', [DanaGatewayMerchantProvisioningController::class, 'createDanaSandboxTestTransaction'])->whereNumber('merchantId');
                Route::post('{merchantId}/api-keys/issue', [DanaGatewayMerchantProvisioningController::class, 'issueApiKey'])->whereNumber('merchantId');
                Route::post('{merchantId}/api-key-issuances/{issuanceId}/cancel', [DanaGatewayMerchantProvisioningController::class, 'cancelIssuance'])->whereNumber(['merchantId', 'issuanceId']);
                Route::delete('{merchantId}/api-keys/{apiKeyId}', [DanaGatewayMerchantProvisioningController::class, 'revokeApiKey'])->whereNumber(['merchantId', 'apiKeyId']);
            });
        });
        Route::apiResource('application-customers', ApplicationCustomerController::class)
            ->only(['index', 'store', 'show', 'update']);
        Route::post('application-customers/{applicationCustomer}/referral/validate', [ApplicationCustomerController::class, 'validateReferral']);
        Route::post('application-customers/{applicationCustomer}/referral', [ApplicationCustomerController::class, 'attachReferral']);
        Route::apiResource('subscription-plans', SubscriptionPlanController::class)
            ->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::apiResource('subscriptions', SubscriptionController::class)
            ->only(['index', 'show']);
        Route::apiResource('invoices', InvoiceController::class)
            ->only(['index', 'store', 'show']);
        Route::post('invoices/{invoice}/subscription/recover', [InvoiceController::class, 'recoverSubscription'])
            ->middleware('throttle:admin-dana-gateway-write');
        Route::post('invoices/{invoice}/checkout', [InvoiceCheckoutController::class, 'store']);
        Route::apiResource('payment-attempts', PaymentAttemptController::class)
            ->only(['index', 'show']);
        Route::post('payment-attempts/{paymentAttempt}/checkout/retry', [PaymentAttemptController::class, 'retryCheckout'])
            ->middleware('throttle:admin-dana-gateway-write');
        Route::post('payment-attempts/{paymentAttempt}/reconcile', [PaymentAttemptController::class, 'reconcile'])
            ->middleware('throttle:admin-payment-reconciliation');
    });
