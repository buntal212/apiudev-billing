<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('admin-login', function (Request $request): Limit {
            return Limit::perMinute(5)->by(mb_strtolower((string) $request->input('username')).'|'.$request->ip());
        });

        RateLimiter::for('admin-dana-gateway-read', function (Request $request): Limit {
            return Limit::perMinute(10)->by('admin-dana-gateway-read:'.$request->user()?->getAuthIdentifier().':'.$request->ip());
        });

        // Satu tampilan status merchant memerlukan readiness, API keys, dan
        // riwayat issuance. Bucket khusus mencegah tiga GET tersebut
        // menghabiskan kuota baca admin umum, tetap terisolasi per admin+IP.
        RateLimiter::for('admin-dana-gateway-management-read', function (Request $request): Limit {
            return Limit::perMinute(30)->by('admin-dana-gateway-management-read:'.$request->user()?->getAuthIdentifier().':'.$request->ip());
        });

        RateLimiter::for('admin-sandbox-audit-read', function (Request $request): Limit {
            return Limit::perMinute(30)->by('admin-sandbox-audit-read:'.$request->user()?->getAuthIdentifier().':'.$request->ip());
        });

        RateLimiter::for('admin-dana-gateway-write', function (Request $request): Limit {
            return Limit::perMinute(5)->by('admin-dana-gateway-write:'.$request->user()?->getAuthIdentifier().':'.$request->ip());
        });

        RateLimiter::for('payment-result', function (Request $request): Limit {
            return Limit::perMinute(30)->by('payment-result:'.$request->route('reference').':'.$request->ip());
        });

        RateLimiter::for('admin-sandbox-payment-result', function (Request $request): Limit {
            return Limit::perMinute(30)->by('admin-sandbox-payment-result:'.$request->user()?->getAuthIdentifier().':'.$request->ip());
        });

        RateLimiter::for('admin-payment-reconciliation', function (Request $request): Limit {
            $paymentAttempt = $request->route('paymentAttempt');
            $paymentAttemptKey = is_object($paymentAttempt) && method_exists($paymentAttempt, 'getKey')
                ? $paymentAttempt->getKey()
                : (string) $paymentAttempt;

            return Limit::perMinute(5)->by('admin-payment-reconciliation:'
                .$request->user()?->getAuthIdentifier().':'
                .$paymentAttemptKey.':'
                .$request->ip());
        });

        RateLimiter::for('admin-integration-test', function (Request $request): Limit {
            $application = $request->route('application');
            $applicationKey = is_object($application) && method_exists($application, 'getKey')
                ? $application->getKey()
                : (string) $application;

            return Limit::perMinute(20)->by('admin-integration-test:'
                .$request->user()?->getAuthIdentifier().':'
                .$applicationKey.':'
                .$request->ip());
        });

        RateLimiter::for('integration-api', function (Request $request): array {
            $token = $request->attributes->get('integration_token');
            $tokenKey = is_object($token) && method_exists($token, 'getKey')
                ? $token->getKey()
                : 'anonymous';
            $application = $request->attributes->get('integration_application');
            $applicationKey = is_object($application) && method_exists($application, 'getKey')
                ? $application->getKey()
                : 'anonymous';

            return [
                Limit::perMinute(60)->by('integration-api:token:'.$applicationKey.':'.$tokenKey),
                Limit::perMinute(120)->by('integration-api:ip:'.$request->ip()),
            ];
        });
    }
}
