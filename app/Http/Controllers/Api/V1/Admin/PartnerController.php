<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListPartnersRequest;
use App\Http\Requests\StorePartnerRequest;
use App\Http\Requests\UpdatePartnerRequest;
use App\Http\Resources\PartnerResource;
use App\Models\Partner;
use App\Models\PartnerAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class PartnerController extends Controller
{
    public function index(ListPartnersRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Partner::class);
        $filters = $request->validated();
        $partners = Partner::query()->withCount('referralCodes')
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")->orWhere('whatsapp_number', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when(array_key_exists('is_active', $filters), fn (Builder $query): Builder => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')->orderBy('id')
            ->paginate($filters['per_page'] ?? 15)->withQueryString();

        return PartnerResource::collection($partners);
    }

    public function store(StorePartnerRequest $request): JsonResponse
    {
        $this->authorize('create', Partner::class);
        $partner = Partner::query()->create($request->validated());
        $this->audit($request, $partner, 'partner.created', null, $partner->getAttributes());

        return (new PartnerResource($partner))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Partner $partner): PartnerResource
    {
        $this->authorize('view', $partner);
        $partner->load(['referralCodes' => fn ($query) => $query->withCount('customerReferrals')->latest()])->loadCount('referralCodes');
        $partner->setAttribute('commission_total', (string) $partner->commissionLedgers()->sum('amount'));

        return new PartnerResource($partner);
    }

    public function update(UpdatePartnerRequest $request, Partner $partner): PartnerResource
    {
        $this->authorize('update', $partner);
        $before = $partner->getOriginal();
        $partner->update($request->validated());
        $this->audit($request, $partner, 'partner.updated', $before, $partner->fresh()->getAttributes());

        return new PartnerResource($partner->refresh()->loadCount('referralCodes'));
    }

    public function destroy(Request $request, Partner $partner): JsonResponse
    {
        $this->authorize('delete', $partner);
        $before = $partner->getAttributes();
        $partner->delete();
        $this->audit($request, $partner, 'partner.deleted', $before, null);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed>|null $after */
    private function audit(Request $request, Partner $partner, string $action, ?array $before, ?array $after): void
    {
        PartnerAudit::query()->create(['partner_id' => $partner->getKey(), 'user_id' => $request->user()?->getAuthIdentifier(), 'action' => $action, 'before' => $before, 'after' => $after, 'ip_address' => $request->ip()]);
    }
}
