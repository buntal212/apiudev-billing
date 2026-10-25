<?php

namespace App\Http\Middleware;

use App\Models\ApplicationIntegrationToken;
use App\Services\ApplicationIntegrationAuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGlobalIntegrationToken
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var ApplicationIntegrationToken|null $token */
        $token = $request->attributes->get('integration_token');
        if ($token?->token_kind !== ApplicationIntegrationToken::KindGlobal) {
            app(ApplicationIntegrationAuditService::class)->record(
                request: $request,
                application: $request->attributes->get('integration_application'),
                token: $token,
                operation: 'token.authorization',
                status: 'failed',
                errorCode: 'global_token_required',
            );

            return response()->json([
                'error_code' => 'global_token_required',
                'message' => 'Global API Token aplikasi diperlukan.',
                'request_id' => $request->attributes->get('integration_request_id'),
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
