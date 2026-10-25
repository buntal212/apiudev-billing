<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicationIntegrationTokenResource;
use App\Models\Application;
use App\Services\ApplicationIntegrationAuditService;
use App\Services\GlobalApplicationTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplicationGlobalIntegrationTokenController extends Controller
{
    public function show(Application $application, GlobalApplicationTokenService $tokenService): JsonResponse
    {
        $this->authorize('view', $application);

        return response()->json(['data' => $tokenService->current($application) ? new ApplicationIntegrationTokenResource($tokenService->current($application)) : null]);
    }

    public function store(Request $request, Application $application, GlobalApplicationTokenService $tokenService, ApplicationIntegrationAuditService $auditService): JsonResponse
    {
        $this->authorize('update', $application);
        $result = $tokenService->generate($application);
        $auditService->record($request, $application, $result['token'], 'global_token.created', 'succeeded');

        return $this->oneTimeResponse($result['token'], $result['plaintext'], Response::HTTP_CREATED);
    }

    public function rotate(Request $request, Application $application, GlobalApplicationTokenService $tokenService, ApplicationIntegrationAuditService $auditService): JsonResponse
    {
        $this->authorize('update', $application);
        $result = $tokenService->rotate($application);
        $auditService->record($request, $application, $result['token'], 'global_token.rotated', 'succeeded', safeDetails: ['transition_minutes' => GlobalApplicationTokenService::TransitionMinutes]);

        return $this->oneTimeResponse($result['token'], $result['plaintext']);
    }

    public function destroy(Request $request, Application $application, GlobalApplicationTokenService $tokenService, ApplicationIntegrationAuditService $auditService): JsonResponse
    {
        $this->authorize('update', $application);
        $token = $tokenService->revoke($application);
        if ($token !== null) {
            $auditService->record($request, $application, $token, 'global_token.revoked', 'succeeded');
        }

        return response()->json(['data' => $token ? new ApplicationIntegrationTokenResource($token) : null]);
    }

    private function oneTimeResponse($token, string $plaintext, int $status = Response::HTTP_OK): JsonResponse
    {
        return response()->json([
            'data' => new ApplicationIntegrationTokenResource($token),
            'token' => $plaintext,
            'token_type' => 'Bearer',
            'warning' => 'Token lengkap hanya ditampilkan sekali. Simpan di backend aplikasi secara aman.',
        ], $status);
    }
}
