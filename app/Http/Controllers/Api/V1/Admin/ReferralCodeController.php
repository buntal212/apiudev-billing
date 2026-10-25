<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListReferralCodesRequest;
use App\Http\Requests\StoreReferralCodeRequest;
use App\Http\Requests\UpdateReferralCodeRequest;
use App\Http\Resources\CustomerReferralResource;
use App\Http\Resources\ReferralCodeResource;
use App\Models\Partner;
use App\Models\PartnerAudit;
use App\Models\ReferralCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class ReferralCodeController extends Controller
{
    public function index(ListReferralCodesRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ReferralCode::class);
        $filters = $request->validated();
        $codes = ReferralCode::query()->with('partner:id,name')->withCount('customerReferrals')
            ->when($filters['search'] ?? null, fn (Builder $query, string $search): Builder => $query->where('code', 'like', "%{$search}%"))
            ->when($filters['partner_id'] ?? null, fn (Builder $query, int $partnerId): Builder => $query->where('partner_id', $partnerId))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type): Builder => $query->where('type', $type))
            ->when(array_key_exists('is_active', $filters), fn (Builder $query): Builder => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')->orderBy('id')
            ->paginate($filters['per_page'] ?? 15)->withQueryString();

        return ReferralCodeResource::collection($codes);
    }

    public function store(StoreReferralCodeRequest $request): JsonResponse
    {
        $this->authorize('create', ReferralCode::class);
        $data = $request->validated();
        $partner = Partner::query()->findOrFail($data['partner_id']);
        abort_unless($partner->is_active, Response::HTTP_UNPROCESSABLE_ENTITY, 'Mitra tidak aktif tidak dapat menerima kode referral baru.');
        [$basePrice, $discount, $customerPrice] = ReferralCode::pricingFor($data['type']);
        $code = ReferralCode::query()->create([...$data, 'code' => mb_strtoupper($data['code']), 'base_price' => $basePrice, 'discount_amount' => $discount, 'customer_price' => $customerPrice, 'created_by' => $request->user()?->getAuthIdentifier()]);
        $this->audit($request, $code, 'referral_code.created', null, $code->getAttributes());

        return (new ReferralCodeResource($code->load('partner:id,name')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(ReferralCode $referralCode): ReferralCodeResource
    {
        $this->authorize('view', $referralCode);

        return new ReferralCodeResource($referralCode->load('partner:id,name')->loadCount('customerReferrals'));
    }

    public function update(UpdateReferralCodeRequest $request, ReferralCode $referralCode): ReferralCodeResource
    {
        $this->authorize('update', $referralCode);
        $before = $referralCode->getOriginal();
        $referralCode->update($request->validated());
        $this->audit($request, $referralCode, 'referral_code.updated', $before, $referralCode->fresh()->getAttributes());

        return new ReferralCodeResource($referralCode->refresh()->load('partner:id,name')->loadCount('customerReferrals'));
    }

    public function usages(ReferralCode $referralCode): AnonymousResourceCollection
    {
        $this->authorize('view', $referralCode);

        return CustomerReferralResource::collection($referralCode->customerReferrals()->with(['customer', 'application'])->latest('locked_at')->paginate(15));
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed>|null $after */
    private function audit(Request $request, ReferralCode $code, string $action, ?array $before, ?array $after): void
    {
        PartnerAudit::query()->create(['partner_id' => $code->partner_id, 'referral_code_id' => $code->getKey(), 'user_id' => $request->user()?->getAuthIdentifier(), 'action' => $action, 'before' => $before, 'after' => $after, 'ip_address' => $request->ip()]);
    }
}
