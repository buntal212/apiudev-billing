<?php

namespace App\Http\Middleware;

use App\Models\ApplicationIntegrationToken;
use App\Services\ApplicationIntegrationAuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApplicationIntegration
{
    public function handle(Request $request, Closure $next, string ...$requiredScopes): Response
    {
        $auditService = app(ApplicationIntegrationAuditService::class);
        $plaintext = $request->bearerToken();
        if (! is_string($plaintext) || $plaintext === '') {
            $auditService->record(
                request: $request,
                application: null,
                token: null,
                operation: 'token.authentication',
                status: 'failed',
                errorCode: 'missing_token',
            );

            return response()->json(['message' => 'Token integrasi tidak valid.'], Response::HTTP_UNAUTHORIZED);
        }

        $token = ApplicationIntegrationToken::query()
            ->with('application')
            ->where('token_hash', hash('sha256', $plaintext))
            ->first();

        if ($token === null) {
            $auditService->record(
                request: $request,
                application: null,
                token: null,
                operation: 'token.authentication',
                status: 'failed',
                errorCode: 'invalid_token',
            );

            return response()->json(['message' => 'Token integrasi tidak valid.'], Response::HTTP_UNAUTHORIZED);
        }

        if ($token->revoked_at !== null) {
            $auditService->record(
                request: $request,
                application: $token->application,
                token: $token,
                operation: 'token.authentication',
                status: 'failed',
                errorCode: 'token_revoked',
            );

            return response()->json(['message' => 'Token integrasi sudah dicabut.'], Response::HTTP_UNAUTHORIZED);
        }

        if ($token->expires_at !== null && $token->expires_at->isPast()) {
            $auditService->record(
                request: $request,
                application: $token->application,
                token: $token,
                operation: 'token.authentication',
                status: 'failed',
                errorCode: 'token_expired',
            );

            return response()->json(['message' => 'Token integrasi sudah kedaluwarsa.'], Response::HTTP_UNAUTHORIZED);
        }

        if (! $token->application?->is_active) {
            $auditService->record(
                request: $request,
                application: $token->application,
                token: $token,
                operation: 'token.authentication',
                status: 'failed',
                errorCode: 'application_inactive',
            );

            return response()->json(['message' => 'Aplikasi tidak aktif untuk integrasi.'], Response::HTTP_FORBIDDEN);
        }

        foreach ($requiredScopes as $scope) {
            if (! $token->hasScope($scope)) {
                $auditService->record(
                    request: $request,
                    application: $token->application,
                    token: $token,
                    operation: 'token.authorization',
                    status: 'failed',
                    errorCode: 'missing_scope',
                    safeDetails: ['required_scope' => $scope],
                );

                return response()->json(['message' => 'Token integrasi tidak memiliki scope yang diperlukan.'], Response::HTTP_FORBIDDEN);
            }
        }

        $request->attributes->set('integration_token', $token);
        $request->attributes->set('integration_application', $token->application);
        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }
}
