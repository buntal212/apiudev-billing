<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationIntegrationToken;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApplicationIntegrationTokenService
{
    /**
     * @param  array<int, string>  $scopes
     * @return array{token: ApplicationIntegrationToken, plaintext: string}
     */
    public function issue(Application $application, string $name, array $scopes, ?string $expiresAt): array
    {
        $allowedScopes = [
            ApplicationIntegrationToken::ScopeCustomersRead,
            ApplicationIntegrationToken::ScopeCustomersWrite,
        ];
        $scopes = array_values(array_unique($scopes));
        if ($scopes === [] || array_diff($scopes, $allowedScopes) !== []) {
            throw ValidationException::withMessages([
                'scopes' => ['Scope token integrasi tidak valid.'],
            ]);
        }

        $plaintext = 'udev_'.Str::lower(bin2hex(random_bytes(32)));
        $token = ApplicationIntegrationToken::query()->create([
            'application_id' => $application->getKey(),
            'name' => trim($name),
            'token_hash' => hash('sha256', $plaintext),
            'token_prefix' => substr($plaintext, 0, 12),
            'scopes' => $scopes,
            'expires_at' => $expiresAt,
        ]);

        return ['token' => $token, 'plaintext' => $plaintext];
    }

    public function revoke(ApplicationIntegrationToken $token): ApplicationIntegrationToken
    {
        if ($token->revoked_at === null) {
            $token->forceFill(['revoked_at' => now()])->save();
        }

        return $token->refresh();
    }
}
