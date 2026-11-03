<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReferralCommissionLedgerResource;
use App\Http\Resources\ReferredWarungResource;
use App\Models\CustomerReferral;
use App\Models\Partner;
use App\Models\ReferralCommissionLedger;
use App\Services\ReferralCommissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class ReferralCommissionController extends Controller
{
    public function __construct(
        private readonly ReferralCommissionService $referralCommissionService
    ) {}

    /**
     * Dashboard ringkasan komisi dan referral.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Partner::class);

        $metrics = $this->referralCommissionService->getSummaryMetrics();

        $recentCommissions = ReferralCommissionLedger::query()
            ->with([
                'partner',
                'referralCode',
                'customerReferral.customer',
                'customerReferral.application',
                'invoice',
                'reversals',
            ])
            ->latest('verified_at')
            ->latest('id')
            ->limit(10)
            ->get();

        return response()->json([
            'metrics' => $metrics,
            'recent_commissions' => ReferralCommissionLedgerResource::collection($recentCommissions),
        ]);
    }

    /**
     * Riwayat komisi referral (ledger) dengan filter dan pagination.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Partner::class);

        $partnerId = $request->input('partner_id');
        $commissionType = $request->input('commission_type');
        $status = $request->input('status');
        $search = $request->input('search');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $sort = $request->input('sort', 'verified_at');
        $direction = $request->input('direction', 'desc');
        $perPage = (int) $request->input('per_page', 15);

        $query = ReferralCommissionLedger::query()
            ->with([
                'partner',
                'referralCode',
                'customerReferral.customer',
                'customerReferral.application',
                'invoice',
                'reversals',
            ])
            ->when($partnerId, fn (Builder $q) => $q->where('partner_id', $partnerId))
            ->when($commissionType, fn (Builder $q) => $q->where('commission_type', $commissionType))
            ->when($status, fn (Builder $q) => $q->where('status', $status))
            ->when($dateFrom, fn (Builder $q) => $q->whereDate('verified_at', '>=', $dateFrom))
            ->when($dateTo, fn (Builder $q) => $q->whereDate('verified_at', '<=', $dateTo))
            ->when($search, function (Builder $q, string $term): void {
                $q->where(function (Builder $sub) use ($term): void {
                    $sub->whereHas('partner', fn (Builder $p) => $p->where('name', 'like', "%{$term}%"))
                        ->orWhereHas('customerReferral.customer', fn (Builder $c) => $c->where('name', 'like', "%{$term}%")->orWhere('owner_name', 'like', "%{$term}%"))
                        ->orWhereHas('invoice', fn (Builder $i) => $i->where('invoice_number', 'like', "%{$term}%"))
                        ->orWhereHas('referralCode', fn (Builder $r) => $r->where('code', 'like', "%{$term}%"));
                });
            });

        $allowedSorts = ['verified_at', 'amount', 'id', 'created_at'];
        $sortField = in_array($sort, $allowedSorts, true) ? $sort : 'verified_at';
        $sortDirection = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        $ledgers = $query->orderBy($sortField, $sortDirection)
            ->orderBy('id', 'desc')
            ->paginate($perPage)
            ->withQueryString();

        return ReferralCommissionLedgerResource::collection($ledgers);
    }

    /**
     * Daftar warung referral beserta ringkasan transaksi & komisi per warung.
     */
    public function warungs(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Partner::class);

        $partnerId = $request->input('partner_id');
        $search = $request->input('search');
        $applicationId = $request->input('application_id');
        $perPage = (int) $request->input('per_page', 15);

        $query = CustomerReferral::query()
            ->with(['customer', 'application', 'partner', 'referralCodeRelation'])
            ->when($partnerId, fn (Builder $q) => $q->where('partner_id', $partnerId))
            ->when($applicationId, fn (Builder $q) => $q->where('application_id', $applicationId))
            ->when($search, function (Builder $q, string $term): void {
                $q->where(function (Builder $sub) use ($term): void {
                    $sub->where('referral_code', 'like', "%{$term}%")
                        ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$term}%")->orWhere('owner_name', 'like', "%{$term}%")->orWhere('phone_number', 'like', "%{$term}%"))
                        ->orWhereHas('partner', fn (Builder $p) => $p->where('name', 'like', "%{$term}%"));
                });
            })
            ->latest('locked_at');

        $warungs = $query->paginate($perPage)->withQueryString();

        // Agregasi metrik per warung
        $warungs->getCollection()->transform(function (CustomerReferral $referral): CustomerReferral {
            $validLedgers = $referral->commissionLedgers()
                ->whereIn('commission_type', [
                    ReferralCommissionLedger::TypeFirstPayment,
                    ReferralCommissionLedger::TypeRenewal,
                ])
                ->where('status', '!=', ReferralCommissionLedger::StatusReversed);

            $referral->setAttribute('payments_count', (clone $validLedgers)->count());
            $referral->setAttribute('commissions_total', number_format((float) (clone $validLedgers)->sum('amount'), 2, '.', ''));
            $referral->setAttribute('last_payment_at', (clone $validLedgers)->max('verified_at'));

            return $referral;
        });

        return ReferredWarungResource::collection($warungs);
    }

    /**
     * Admin reversal / refund komisi referral dengan audit trail.
     */
    public function reverse(Request $request, ReferralCommissionLedger $referralCommission): JsonResponse
    {
        $this->authorize('update', $referralCommission->partner);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if ($referralCommission->commission_type === ReferralCommissionLedger::TypeReversal) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Ledger reversal tidak dapat dibalikkan kembali.');
        }

        $reversal = $this->referralCommissionService->reverseCommission(
            $referralCommission->invoice,
            $validated['reason'],
            $request->user(),
            $request->ip()
        );

        return response()->json([
            'message' => 'Komisi referral berhasil dibalikkan.',
            'data' => new ReferralCommissionLedgerResource($reversal->load([
                'partner',
                'referralCode',
                'customerReferral.customer',
                'invoice',
            ])),
        ]);
    }
}
