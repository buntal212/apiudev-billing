<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListInvoicesRequest;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\CheckoutOutboxMessage;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Services\CheckoutService;
use App\Services\DanaGatewayClient;
use App\Services\DanaGatewayMerchantResolver;
use App\Services\InvoiceService;
use App\Services\InvoiceSettlementService;
use App\Services\PaymentAttemptService;
use App\Services\PaymentReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    public function index(ListInvoicesRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Invoice::class);

        $filters = $request->validated();
        $invoices = Invoice::query()
            ->with([
                'customer.application.paymentGateways' => function ($query): void {
                    $query->where('environment', 'sandbox')->where('is_active', true);
                },
                'customer.referral.partner',
                'plan',
                'subscription',
                'paymentAttempts' => function ($query): void {
                    $query->orderBy('attempt_number', 'desc');
                },
            ])
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $search = trim($search);
                $query->where(function (Builder $q) use ($search): void {
                    $q->where('invoice_number', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn (Builder $cq) => $cq->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('plan', fn (Builder $pq) => $pq->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($filters['application_id'] ?? null, function (Builder $query, int $applicationId): void {
                $query->whereHas('customer', function (Builder $query) use ($applicationId): void {
                    $query->where('application_id', $applicationId);
                });
            })
            ->when($filters['application_customer_id'] ?? null, function (Builder $query, int $customerId): void {
                $query->where('application_customer_id', $customerId);
            })
            ->when($filters['subscription_plan_id'] ?? null, function (Builder $query, int $planId): void {
                $query->where('subscription_plan_id', $planId);
            })
            ->when($filters['subscription_id'] ?? null, function (Builder $query, int $subscriptionId): void {
                $query->where('subscription_id', $subscriptionId);
            })
            ->when($filters['status'] ?? null, function (Builder $query, string $status): void {
                $query->where('status', $status);
            })
            ->when($filters['due_from'] ?? null, function (Builder $query, string $dueFrom): void {
                $query->where('due_at', '>=', $dueFrom);
            })
            ->when($filters['due_to'] ?? null, function (Builder $query, string $dueTo): void {
                $query->where('due_at', '<=', $dueTo);
            })
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return InvoiceResource::collection($invoices);
    }

    public function store(StoreInvoiceRequest $request, InvoiceService $invoiceService): JsonResponse
    {
        $this->authorize('create', Invoice::class);

        $data = $request->validated();
        $invoice = $invoiceService->createInvoice(
            customerId: $data['application_customer_id'],
            planId: $data['subscription_plan_id'],
            subscriptionId: $data['subscription_id'] ?? null,
            dueAt: CarbonImmutable::parse($data['due_at']),
            idempotencyKey: $data['idempotency_key'],
            periodCount: $data['period_count'] ?? 1,
        );

        return (new InvoiceResource($invoice->load(['customer.referral.partner', 'plan', 'subscription'])))
            ->response()
            ->setStatusCode(
                $invoice->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK,
            );
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        $this->authorize('view', $invoice);

        return new InvoiceResource($invoice->load([
            'customer',
            'customer.referral.partner',
            'plan',
            'subscription.periodEvents',
            'paymentAttempts.latestReconciliationRun',
            'paymentAttempts.checkoutOutbox',
            'paymentAttempts.paymentAllocation',
            'paymentAttempts.settlementEvents',
            'referralCommissionLedgers.partner',
            'referralCommissionLedgers.referralCode',
        ]));
    }

    public function reconcile(
        Invoice $invoice,
        PaymentReconciliationService $reconciliationService,
    ): InvoiceResource {
        $this->authorize('update', $invoice);

        $attempt = $invoice->paymentAttempts()
            ->latest('attempt_number')
            ->first();

        if ($attempt === null) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Invoice belum memiliki percobaan pembayaran untuk direkonsiliasi.');
        }

        $reconciliationService->reconcile($attempt, true);

        return $this->show($invoice->fresh());
    }

    public function cancelCheckout(
        Request $request,
        Invoice $invoice,
        DanaGatewayClient $gatewayClient,
        DanaGatewayMerchantResolver $merchantResolver,
        PaymentAttemptService $paymentAttemptService,
    ): InvoiceResource {
        $this->authorize('update', $invoice);

        $attempt = $invoice->paymentAttempts()
            ->latest('attempt_number')
            ->first();

        if ($attempt === null) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Invoice belum memiliki percobaan pembayaran untuk dibatalkan.');
        }

        if (! in_array($attempt->status, [PaymentAttempt::StatusInitiated, PaymentAttempt::StatusPending], true)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, "Percobaan pembayaran dengan status '{$attempt->status}' tidak dapat dibatalkan.");
        }

        $reason = (string) ($request->input('reason') ?? 'Dibatalkan oleh admin melalui invoice.');

        if ($attempt->provider === 'dana' && ! empty($attempt->gateway_payment_id)) {
            try {
                $merchant = $merchantResolver->resolve(
                    (string) $attempt->gateway_merchant_code,
                    (string) $attempt->gateway_environment,
                );
                $gatewayClient->cancelPayment($merchant, (string) $attempt->gateway_payment_id, $reason);
            } catch (\Throwable $e) {
                Log::warning('Gagal membatalkan transaksi DANA di gateway.', ['attempt_id' => $attempt->id, 'error' => $e->getMessage()]);
            }
        }

        $paymentAttemptService->transitionStatus(
            $attempt,
            PaymentAttempt::StatusCancelled,
            failureCode: 'user_cancelled',
            failureMessage: $reason,
        );

        $outbox = CheckoutOutboxMessage::query()
            ->where('payment_attempt_id', $attempt->id)
            ->first();

        if ($outbox !== null && $outbox->status !== CheckoutOutboxMessage::StatusCompleted) {
            $outbox->update([
                'status' => CheckoutOutboxMessage::StatusFailed,
                'error_code' => 'attempt_cancelled',
            ]);
        }

        return $this->show($invoice->fresh());
    }

    public function sandboxSimulatePayment(
        Request $request,
        Invoice $invoice,
        CheckoutService $checkoutService,
        PaymentAttemptService $paymentAttemptService,
    ): JsonResponse {
        $this->authorize('update', $invoice);

        if (config('services.dana_gateway.environment') !== 'sandbox') {
            abort(Response::HTTP_FORBIDDEN, 'Simulasi pembayaran hanya diizinkan pada environment sandbox.');
        }

        $validated = $request->validate([
            'action' => ['nullable', 'string', 'in:paid,failed,expired,cancelled'],
        ]);
        $action = $validated['action'] ?? 'failed';

        if ($action === 'paid') {
            throw ValidationException::withMessages([
                'action' => ['Endpoint simulasi Sandbox tidak dapat menandai invoice paid atau mengaktifkan subscription. Settlement operasional hanya dapat diproses melalui konfirmasi gateway yang valid.'],
            ]);
        }

        if ($invoice->status === Invoice::StatusPaid) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Invoice sudah berstatus lunas.');
        }

        if ($invoice->status !== Invoice::StatusPending) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Hanya invoice pending yang dapat disimulasikan.');
        }

        $attempt = $invoice->paymentAttempts()->latest('attempt_number')->first();
        if ($attempt !== null && (
            in_array($attempt->status, [PaymentAttempt::StatusFailed, PaymentAttempt::StatusExpired, PaymentAttempt::StatusCancelled], true)
            || ($attempt->checkout_expires_at !== null && $attempt->checkout_expires_at->isPast())
        )) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Percobaan pembayaran telah kedaluwarsa atau berstatus terminal dan tidak dapat disimulasikan. Transaksi baru tidak dibuat otomatis.');
        }

        if ($attempt === null) {
            $attempt = $checkoutService->startCheckout($invoice, 'sim-'.Str::uuid());
        }

        if ($attempt->status === PaymentAttempt::StatusInitiated) {
            $attempt = $paymentAttemptService->transitionStatus(
                $attempt,
                PaymentAttempt::StatusPending,
            );
        }

        $terminalStatus = match ($action) {
            'expired' => PaymentAttempt::StatusExpired,
            'cancelled' => PaymentAttempt::StatusCancelled,
            default => PaymentAttempt::StatusFailed,
        };

        $paymentAttemptService->transitionStatus(
            $attempt,
            $terminalStatus,
            null,
            'sandbox_simulated_'.$action,
            'Simulasi kegagalan pembayaran di DANA Sandbox.',
        );

        return (new InvoiceResource($invoice->fresh()->load([
            'customer',
            'customer.referral.partner',
            'plan',
            'subscription.periodEvents',
            'paymentAttempts.latestReconciliationRun',
            'paymentAttempts.checkoutOutbox',
            'paymentAttempts.paymentAllocation',
            'paymentAttempts.settlementEvents',
        ])))->additional([
            'test' => [
                'simulation_action' => $action,
                'is_test' => true,
                'message' => 'Simulasi kegagalan gateway berhasil dicatat pada payment attempt. Status invoice tetap pending dan subscription tidak berubah.',
            ],
        ])->response();
    }

    public function recoverSubscription(
        Invoice $invoice,
        InvoiceSettlementService $settlementService,
    ): InvoiceResource {
        $this->authorize('update', $invoice);

        $recoveredInvoice = $settlementService->recoverSubscription($invoice);

        return new InvoiceResource($recoveredInvoice->load([
            'customer',
            'customer.referral.partner',
            'plan',
            'subscription.latestPeriodEvent',
        ]));
    }
}
