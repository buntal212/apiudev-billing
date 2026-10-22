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
    }
}
