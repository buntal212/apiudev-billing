<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreApplicationIntegrationTokenRequest;
use App\Http\Resources\ApplicationIntegrationTokenResource;
use App\Models\Application;
use App\Models\ApplicationIntegrationToken;
use App\Services\ApplicationIntegrationAuditService;
use App\Services\ApplicationIntegrationTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class ApplicationIntegrationTokenController extends Controller
{
    public function index(Request $request, Application $application): AnonymousResourceCollection
    {
        $this->authorize('view', $application);

        return ApplicationIntegrationTokenResource::collection(
            $application->integrationTokens()->latest()->paginate(min(max((int) $request->input('per_page', 15), 1), 100)),
        );
    }

    public function store(
        StoreApplicationIntegrationTokenRequest $request,
        Application $application,
        ApplicationIntegrationTokenService $tokenService,
        ApplicationIntegrationAuditService $auditService,
    ): JsonResponse {
        $this->authorize('update', $application);

        $result = $tokenService->issue(
            application: $application,
            name: $request->string('name')->toString(),
            scopes: $request->input('scopes', []),
            expiresAt: $request->input('expires_at'),
        );
        $auditService->record(
            request: $request,
            application: $application,
            token: $result['token'],
            operation: 'token.created',
            status: 'succeeded',
            safeDetails: ['scopes' => $result['token']->scopes],
        );

        return response()->json([
            'data' => new ApplicationIntegrationTokenResource($result['token']),
            'token' => $result['plaintext'],
            'token_type' => 'Bearer',
            'warning' => 'Token lengkap hanya ditampilkan sekali. Simpan di server aplikasi secara aman.',
        ], Response::HTTP_CREATED);
    }

    public function destroy(
        Application $application,
        ApplicationIntegrationToken $integrationToken,
        ApplicationIntegrationTokenService $tokenService,
        ApplicationIntegrationAuditService $auditService,
    ): ApplicationIntegrationTokenResource {
        $this->authorize('update', $application);
        abort_unless($integrationToken->application_id === $application->getKey(), Response::HTTP_NOT_FOUND);

        $token = $tokenService->revoke($integrationToken);
        $auditService->record(
            request: request(),
            application: $application,
            token: $token,
            operation: 'token.revoked',
            status: 'succeeded',
        );

        return new ApplicationIntegrationTokenResource($token);
    }
}
