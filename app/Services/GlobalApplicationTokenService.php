<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationIntegrationToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GlobalApplicationTokenService
{
    public const int TransitionMinutes = 15;

    /** @return array{token: ApplicationIntegrationToken, plaintext: string} */
    public function generate(Application $application): array
    {
        return DB::transaction(function () use ($application): array {
            $lockedApplication = Application::query()->lockForUpdate()->findOrFail($application->getKey());
            if ($this->current($lockedApplication) !== null) {
                throw ValidationException::withMessages(['token' => ['Global API Token aktif sudah tersedia. Gunakan rotasi bila token perlu diganti.']]);
            }

            return $this->issue($lockedApplication);
        });
    }

    /** @return array{token: ApplicationIntegrationToken, plaintext: string, previous: ?ApplicationIntegrationToken} */
    public function rotate(Application $application): array
    {
        return DB::transaction(function () use ($application): array {
            $lockedApplication = Application::query()->lockForUpdate()->findOrFail($application->getKey());
            $previous = $this->current($lockedApplication);
            if ($previous !== null) {
                $previous->forceFill(['expires_at' => now()->addMinutes(self::TransitionMinutes)])->save();
            }
            $issued = $this->issue($lockedApplication);

            return [...$issued, 'previous' => $previous?->refresh()];
        });
    }

    public function revoke(Application $application): ?ApplicationIntegrationToken
    {
        return DB::transaction(function () use ($application): ?ApplicationIntegrationToken {
            $lockedApplication = Application::query()->lockForUpdate()->findOrFail($application->getKey());
            $token = $this->current($lockedApplication);
            if ($token !== null) {
                $token->forceFill(['revoked_at' => now()])->save();
            }

            return $token?->refresh();
        });
    }

    public function current(Application $application): ?ApplicationIntegrationToken
    {
        return $application->integrationTokens()
            ->where('token_kind', ApplicationIntegrationToken::KindGlobal)
            ->whereNull('revoked_at')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest('id')
            ->first();
    }

    /** @return array{token: ApplicationIntegrationToken, plaintext: string} */
    private function issue(Application $application): array
    {
        $plaintext = 'udev_global_'.bin2hex(random_bytes(32));
        $token = ApplicationIntegrationToken::query()->create([
            'application_id' => $application->getKey(),
            'name' => 'Global API Token',
            'token_kind' => ApplicationIntegrationToken::KindGlobal,
            'token_hash' => hash('sha256', $plaintext),
            'token_prefix' => substr($plaintext, 0, 16),
            'scopes' => [],
        ]);

        return ['token' => $token, 'plaintext' => $plaintext];
    }
}
