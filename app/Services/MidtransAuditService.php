<?php

namespace App\Services;

use App\Models\MidtransGatewayAudit;
use Illuminate\Http\Request;

class MidtransAuditService
{
    /**
     * Record a Midtrans administrative or gateway security audit.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function record(
        string $merchantCode,
        string $action,
        string $status,
        ?string $responseCode = null,
        ?int $latencyMs = null,
        ?array $metadata = null,
        ?Request $request = null,
        string $environment = 'sandbox'
    ): MidtransGatewayAudit {
        $req = $request ?: request();

        return MidtransGatewayAudit::create([
            'admin_user_id' => $req?->user()?->id,
            'merchant_code' => $merchantCode,
            'environment' => $environment,
            'action' => $action,
            'status' => $status,
            'response_code' => $responseCode,
            'latency_ms' => $latencyMs,
            'ip_address' => $req?->ip(),
            'user_agent' => substr((string) ($req?->userAgent() ?? ''), 0, 255),
            'metadata' => $metadata ? $this->sanitizeMetadata($metadata) : null,
            'created_at' => now(),
        ]);
    }

    /**
     * Strict sanitization to ensure secrets never reach the audit table.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function sanitizeMetadata(array $data): array
    {
        $sensitiveKeys = [
            'server_key',
            'client_key',
            'signature_key',
            'signature_secret_key',
            'encrypted_server_key',
            'encrypted_signature_key',
            'password',
            'token',
            'authorization',
        ];

        $sanitized = [];
        foreach ($data as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if (in_array($normalizedKey, $sensitiveKeys, true)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeMetadata($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
