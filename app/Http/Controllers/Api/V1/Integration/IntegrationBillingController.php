<?php

namespace App\Http\Controllers\Api\V1\Integration;

use App\Http\Controllers\Controller;
use App\Http\Requests\IntegrationInvoicePreviewRequest;
use App\Http\Requests\IntegrationStoreInvoiceRequest;
use App\Http\Requests\StartInvoiceCheckoutRequest;
use App\Http\Resources\IntegrationInvoiceResource;
use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\ApplicationIntegrationToken;
use App\Models\Invoice;
use App\Models\SubscriptionPlan;
use App\Services\ApplicationIntegrationAuditService;
use App\Services\CheckoutService;
use App\Services\IntegrationCustomerService;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class IntegrationBillingController extends Controller
{
    public function preview(
        IntegrationInvoicePreviewRequest $request,
        string $externalId,
        IntegrationCustomerService $customers,
        ApplicationIntegrationAuditService $audits,
    ): JsonResponse {
        [$application, $token] = $this->context($request);
        $customer = $this->customerOrFail($customers, $application, $externalId);
        $plan = $this->planOrFail($application, $request->string('subscription_plan_code')->toString());
        $periodCount = max(1, min(12, $request->integer('period_count', 1)));
        $referral = $customer->referral()->first();
        $unitPrice = $plan->price;
        $unitDiscount = $referral?->discount_amount ?? '0.00';
        $unitFinalPrice = $referral?->customer_price ?? $unitPrice;

        $normalPrice = $this->multiplyPrice($unitPrice, $periodCount);
        $discount = $this->multiplyPrice($unitDiscount, $periodCount);
        $finalPrice = $this->multiplyPrice($unitFinalPrice, $periodCount);
        $durationDays = 30 * $periodCount;

        $audits->record(
            request: $request,
            application: $application,
            token: $token,
            operation: 'invoice.previewed',
            status: 'succeeded',
            externalCustomerId: $externalId,
            safeDetails: [
                'plan_code' => $plan->code,
                'period_count' => $periodCount,
                'has_referral' => $referral !== null,
            ],
        );

        return $this->response($request, [
            'customer_external_id' => $customer->external_customer_id,
            'plan' => [
                'code' => $plan->code,
                'name' => $plan->name,
                'duration_days' => $durationDays,
                'period_count' => $periodCount,
            ],
            'pricing' => [
                'unit_price' => $unitPrice,
                'period_count' => $periodCount,
                'normal_price' => $normalPrice,
                'referral_code' => $referral?->referral_code,
                'referral_type' => $referral?->referral_type,
                'discount_amount' => $discount,
                'final_price' => $finalPrice,
                'currency' => $plan->currency,
            ],
        ]);
    }

    public function store(
        IntegrationStoreInvoiceRequest $request,
        string $externalId,
        IntegrationCustomerService $customers,
        InvoiceService $invoices,
        ApplicationIntegrationAuditService $audits,
    ): JsonResponse {
        [$application, $token] = $this->context($request);
        $customer = $this->customerOrFail($customers, $application, $externalId);
        $plan = $this->planOrFail($application, $request->string('subscription_plan_code')->toString());
        $subscription = $customer->subscriptions()->latest('expires_at')->first();
        $periodCount = max(1, min(12, $request->integer('period_count', 1)));
        $invoice = $invoices->createInvoice(
            customerId: $customer->getKey(),
            planId: $plan->getKey(),
            subscriptionId: $subscription?->getKey(),
            dueAt: $request->date('due_at'),
            idempotencyKey: $request->string('idempotency_key')->toString(),
            periodCount: $periodCount,
        );

        $invoice = $this->loadInvoice($invoice);
        $audits->record(
            request: $request,
            application: $application,
            token: $token,
            operation: 'invoice.created',
            status: 'succeeded',
            externalCustomerId: $externalId,
            safeDetails: ['invoice_number' => $invoice->invoice_number, 'plan_code' => $plan->code, 'period_count' => $periodCount],
        );

        return (new IntegrationInvoiceResource($invoice))
            ->additional(['request_id' => $request->attributes->get('integration_request_id')])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(
        Request $request,
        string $invoiceNumber,
        ApplicationIntegrationAuditService $audits,
    ): JsonResponse {
        [$application, $token] = $this->context($request);
        $invoice = Invoice::query()
            ->where('invoice_number', $invoiceNumber)
            ->whereHas('customer', fn ($query) => $query->where('application_id', $application->getKey()))
            ->first();
        abort_if($invoice === null, Response::HTTP_NOT_FOUND, 'Invoice tidak ditemukan pada aplikasi ini.');

        $invoice = $this->loadInvoice($invoice);
        $audits->record(
            request: $request,
            application: $application,
            token: $token,
            operation: 'invoice.read',
            status: 'succeeded',
            externalCustomerId: $invoice->customer->external_customer_id,
            safeDetails: ['invoice_number' => $invoice->invoice_number],
        );

        return (new IntegrationInvoiceResource($invoice))
            ->additional(['request_id' => $request->attributes->get('integration_request_id')])
            ->response();
    }

    public function checkout(
        StartInvoiceCheckoutRequest $request,
        string $invoiceNumber,
        CheckoutService $checkout,
        ApplicationIntegrationAuditService $audits,
    ): JsonResponse {
        [$application, $token] = $this->context($request);
        $invoice = Invoice::query()
            ->where('invoice_number', $invoiceNumber)
            ->whereHas('customer', fn ($query) => $query->where('application_id', $application->getKey()))
            ->first();
        abort_if($invoice === null, Response::HTTP_NOT_FOUND, 'Invoice tidak ditemukan pada aplikasi ini.');

        $paymentMethod = (string) ($request->validated('payment_method') ?? 'dana_wallet');
        $attempt = $checkout->startCheckout($invoice, $request->validated('idempotency_key'), $paymentMethod);
        $invoice = $this->loadInvoice($invoice);
        $audits->record(
            request: $request,
            application: $application,
            token: $token,
            operation: 'invoice.checkout_started',
            status: 'succeeded',
            externalCustomerId: $invoice->customer->external_customer_id,
            safeDetails: [
                'invoice_number' => $invoice->invoice_number,
                'payment_attempt' => $attempt->attempt_number,
                'payment_method' => $paymentMethod,
            ],
        );

        $isReady = $attempt->checkout_url !== null
            || ! empty($attempt->metadata['qr_content'] ?? null)
            || ! empty($attempt->metadata['payment_code'] ?? null);

        return (new IntegrationInvoiceResource($invoice))
            ->additional(['request_id' => $request->attributes->get('integration_request_id')])
            ->response()
            ->setStatusCode($isReady ? Response::HTTP_OK : Response::HTTP_ACCEPTED);
    }

    public function transactions(
        Request $request,
        string $externalId,
        IntegrationCustomerService $customers,
        ApplicationIntegrationAuditService $audits,
    ): AnonymousResourceCollection {
        [$application, $token] = $this->context($request);
        $customer = $this->customerOrFail($customers, $application, $externalId);
        $invoices = $customer->invoices()
            ->with(['plan', 'subscription.plan', 'subscription.periodEvents', 'paymentAttempts.checkoutOutbox', 'settlementEvents'])
            ->latest('issued_at')
            ->paginate(min(max($request->integer('per_page', 15), 1), 100))
            ->withQueryString();

        $audits->record(
            request: $request,
            application: $application,
            token: $token,
            operation: 'billing.transactions.read',
            status: 'succeeded',
            externalCustomerId: $externalId,
            safeDetails: ['count' => $invoices->total()],
        );

        return IntegrationInvoiceResource::collection($invoices)
            ->additional(['request_id' => $request->attributes->get('integration_request_id')]);
    }

    /**
     * @return array{0: Application, 1: ApplicationIntegrationToken}
     */
    private function context(Request $request): array
    {
        /** @var Application $application */
        $application = $request->attributes->get('integration_application');
        /** @var ApplicationIntegrationToken $token */
        $token = $request->attributes->get('integration_token');

        return [$application, $token];
    }

    private function customerOrFail(
        IntegrationCustomerService $customers,
        Application $application,
        string $externalId,
    ): ApplicationCustomer {
        $customer = $customers->find($application, $externalId);
        abort_if($customer === null, Response::HTTP_NOT_FOUND, 'Pelanggan eksternal tidak ditemukan.');

        return $customer;
    }

    private function planOrFail(Application $application, string $planCode): SubscriptionPlan
    {
        $plan = SubscriptionPlan::query()
            ->where('application_id', $application->getKey())
            ->where('code', $planCode)
            ->where('is_active', true)
            ->first();
        abort_if($plan === null, Response::HTTP_NOT_FOUND, 'Paket aktif tidak ditemukan pada aplikasi ini.');

        return $plan;
    }

    private function loadInvoice(Invoice $invoice): Invoice
    {
        return $invoice->fresh([
            'customer',
            'plan',
            'subscription.plan',
            'subscription.periodEvents',
            'paymentAttempts.checkoutOutbox',
            'settlementEvents',
        ]);
    }

    /** @param array<string, mixed> $data */
    private function response(Request $request, array $data): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'request_id' => $request->attributes->get('integration_request_id'),
        ]);
    }

    private function multiplyPrice(string $price, int $multiplier): string
    {
        if (preg_match('/\A(\d+)(?:\.(\d{1,2}))?\z/', $price, $matches) !== 1) {
            return $price;
        }

        $cents = ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '0', 2, '0');
        $total = $cents * $multiplier;

        return intdiv($total, 100).'.'.str_pad((string) ($total % 100), 2, '0', STR_PAD_LEFT);
    }
}
