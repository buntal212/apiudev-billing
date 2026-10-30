<?php

namespace App\Services;

use App\Models\DokuGatewayAudit;
use Illuminate\Http\Request;

class DokuAuditService
{
    private const array RedactedKeys = [
        'private_key',
        'access_token',
        'raw_token',
        'token',
        'authorization',
        'signature',
        'x-signature',
        'password',
        'secret',
    ];

    /**
     * Mencatat audit log operasional DOKU Gateway tanpa menyimpan rahasia/secret.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $merchantCode,
        string $action,
        string $status,
        ?string $responseCode = null,
        ?int $latencyMs = null,
        array $metadata = [],
        ?Request $request = null,
        ?int $adminUserId = null
    ): DokuGatewayAudit {
        $ip = $request?->ip();
        $userAgent = $request?->userAgent();
        $userId = $adminUserId ?? $request?->user()?->id;

        $safeMetadata = $this->sanitizeMetadata($metadata);

        return DokuGatewayAudit::query()->create([
            'admin_user_id' => $userId,
            'merchant_code' => $merchantCode,
            'environment' => 'sandbox',
            'action' => $action,
            'status' => $status,
            'response_code' => $responseCode,
            'latency_ms' => $latencyMs,
            'ip_address' => $ip,
            'user_agent' => $userAgent ? substr($userAgent, 0, 255) : null,
            'metadata' => empty($safeMetadata) ? null : $safeMetadata,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function sanitizeMetadata(array $metadata): array
    {
        $sanitized = [];

        foreach ($metadata as $key => $value) {
            $lowerKey = strtolower((string) $key);

            if (in_array($lowerKey, self::RedactedKeys, true)) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeMetadata($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
