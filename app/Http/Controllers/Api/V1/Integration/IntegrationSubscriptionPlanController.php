<?php

namespace App\Http\Controllers\Api\V1\Integration;

use App\Http\Controllers\Controller;
use App\Http\Resources\IntegrationSubscriptionPlanResource;
use App\Models\Application;
use App\Models\ApplicationIntegrationToken;
use App\Models\SubscriptionPlan;
use App\Services\ApplicationIntegrationAuditService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class IntegrationSubscriptionPlanController extends Controller
{
    public function index(
        Request $request,
        ApplicationIntegrationAuditService $auditService,
    ): AnonymousResourceCollection {
        [$application, $token] = $this->context($request);

        $plans = SubscriptionPlan::query()
            ->where('application_id', $application->getKey())
            ->where('is_active', true)
            ->orderBy('code')
            ->orderBy('id')
            ->paginate(min(max($request->integer('per_page', 15), 1), 100))
            ->withQueryString();

        $auditService->record(
            request: $request,
            application: $application,
            token: $token,
            operation: 'subscription_plans.read',
            status: 'succeeded',
            safeDetails: ['count' => $plans->total()],
        );

        return IntegrationSubscriptionPlanResource::collection($plans);
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
}
