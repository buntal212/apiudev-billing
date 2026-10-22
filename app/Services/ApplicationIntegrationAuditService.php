<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationIntegrationAudit;
use App\Models\ApplicationIntegrationToken;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApplicationIntegrationAuditService
{
    /**
     * @param  array<string, mixed>  $safeDetails
     */
    public function record(
        Request $request,
        ?Application $application,
        ?ApplicationIntegrationToken $token,
        string $operation,
        string $status,
        ?string $externalCustomerId = null,
        ?string $errorCode = null,
        array $safeDetails = [],
    ): ApplicationIntegrationAudit {
        $requestId = $request->attributes->get('integration_request_id')
            ?: $request->header('X-Request-Id')
            ?: (string) Str::uuid();
        $request->attributes->set('integration_request_id', $requestId);

        return ApplicationIntegrationAudit::query()->create([
            'application_id' => $application?->getKey() ?? $token?->application_id,
            'application_integration_token_id' => $token?->getKey(),
            'external_customer_id' => $externalCustomerId,
            'operation' => $operation,
            'status' => $status,
            'error_code' => $errorCode,
            'request_id' => Str::limit((string) $requestId, 100, ''),
            'safe_details' => $safeDetails,
        ]);
    }
}
