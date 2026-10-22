<?php

namespace App\Http\Middleware;

use App\Models\ApplicationIntegrationToken;
use App\Services\ApplicationIntegrationAuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIntegrationScope
{
    public function handle(Request $request, Closure $next, string ...$requiredScopes): Response
    {
        /** @var ApplicationIntegrationToken|null $token */
        $token = $request->attributes->get('integration_token');
        if ($token === null) {
            return response()->json(['message' => 'Token integrasi tidak valid.'], Response::HTTP_UNAUTHORIZED);
        }

        foreach ($requiredScopes as $scope) {
            if (! $token->hasScope($scope)) {
                app(ApplicationIntegrationAuditService::class)->record(
                    request: $request,
                    application: $request->attributes->get('integration_application'),
                    token: $token,
                    operation: 'token.authorization',
                    status: 'failed',
                    errorCode: 'missing_scope',
                    safeDetails: ['required_scope' => $scope],
                );

                return response()->json(['message' => 'Token integrasi tidak memiliki scope yang diperlukan.'], Response::HTTP_FORBIDDEN);
            }
        }

        return $next($request);
    }
}
