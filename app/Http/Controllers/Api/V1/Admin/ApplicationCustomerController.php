<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminAttachCustomerReferralRequest;
use App\Http\Requests\ListApplicationCustomersRequest;
use App\Http\Requests\StoreApplicationCustomerRequest;
use App\Http\Requests\UpdateApplicationCustomerRequest;
use App\Http\Resources\ApplicationCustomerResource;
use App\Models\ApplicationCustomer;
use App\Services\IntegrationReferralService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class ApplicationCustomerController extends Controller
{
    public function index(ListApplicationCustomersRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ApplicationCustomer::class);

        $filters = $request->validated();
        $customers = ApplicationCustomer::query()
            ->with(['application', 'referral.partner', 'latestSubscription.plan'])
            ->withCount(['invoices', 'subscriptions'])
            ->when($filters['application_id'] ?? null, function (Builder $query, int $applicationId): void {
                $query->where('application_id', $applicationId);
            })
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('external_customer_id', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when(array_key_exists('is_active', $filters), function (Builder $query) use ($request): void {
                $query->where('is_active', $request->boolean('is_active'));
            })
            ->when(array_key_exists('is_test', $filters), function (Builder $query) use ($request): void {
                $query->where('is_test', $request->boolean('is_test'));
            })
            ->when($filters['source'] ?? null, function (Builder $query, string $source): void {
                $query->where('source', $source);
            })
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return ApplicationCustomerResource::collection($customers);
    }

    public function store(StoreApplicationCustomerRequest $request): JsonResponse
    {
        $this->authorize('create', ApplicationCustomer::class);

        $applicationCustomer = ApplicationCustomer::create($request->validated());

        return (new ApplicationCustomerResource($applicationCustomer->load('application')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(ApplicationCustomer $applicationCustomer): ApplicationCustomerResource
    {
        $this->authorize('view', $applicationCustomer);

        return new ApplicationCustomerResource($applicationCustomer->load([
            'application',
            'referral.partner',
            'invoices.paymentAttempts.latestReconciliationRun',
            'invoices.paymentAttempts.paymentAllocation',
            'invoices.paymentAttempts.settlementEvents',
            'invoices.paymentAttempts.checkoutOutbox',
            'subscriptions.plan',
            'subscriptions.periodEvents',
        ])->loadCount(['invoices', 'subscriptions']));
    }

    public function update(UpdateApplicationCustomerRequest $request, ApplicationCustomer $applicationCustomer): ApplicationCustomerResource
    {
        $this->authorize('update', $applicationCustomer);

        $applicationCustomer->update($request->validated());

        return new ApplicationCustomerResource($applicationCustomer->refresh()->load(['application', 'referral.partner']));
    }

    public function validateReferral(AdminAttachCustomerReferralRequest $request, ApplicationCustomer $applicationCustomer, IntegrationReferralService $referrals): JsonResponse
    {
        $this->authorize('update', $applicationCustomer);
        abort_if($applicationCustomer->referral()->exists(), Response::HTTP_CONFLICT, 'Referral pelanggan sudah terkunci dan tidak dapat diganti.');

        return response()->json(['data' => $referrals->validate($applicationCustomer->application, $request->string('referral_code')->toString())]);
    }

    public function attachReferral(AdminAttachCustomerReferralRequest $request, ApplicationCustomer $applicationCustomer, IntegrationReferralService $referrals): ApplicationCustomerResource
    {
        $this->authorize('update', $applicationCustomer);
        $referrals->attach($applicationCustomer->application, $applicationCustomer, $request->string('referral_code')->toString());

        return new ApplicationCustomerResource($applicationCustomer->refresh()->load(['application', 'referral.partner']));
    }
}
