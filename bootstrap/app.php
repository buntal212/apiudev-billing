<?php

use App\Exceptions\IntegrationConflictException;
use App\Http\Middleware\AuthenticateApplicationIntegration;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureGlobalIntegrationToken;
use App\Http\Middleware\EnsureIntegrationScope;
use App\Services\ApplicationIntegrationAuditService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('payments:reconcile-pending')
            ->everyMinute()
            ->withoutOverlapping(1)
            ->onOneServer();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'abilities' => CheckAbilities::class,
            'integration.auth' => AuthenticateApplicationIntegration::class,
            'integration.scope' => EnsureIntegrationScope::class,
            'integration.global' => EnsureGlobalIntegrationToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ValidationException $exception, Request $request): JsonResponse {
            if (str_starts_with($request->path(), 'api/v1/integrations')
                || str_contains($request->path(), '/integration-tests')) {
                $token = $request->attributes->get('integration_token');
                app(ApplicationIntegrationAuditService::class)->record(
                    request: $request,
                    application: $request->attributes->get('integration_application')
                        ?: (is_object($request->route('application')) ? $request->route('application') : null),
                    token: is_object($token) ? $token : null,
                    operation: 'request.validation',
                    status: 'failed',
                    externalCustomerId: $request->route('externalId') ?: $request->input('external_customer_id'),
                    errorCode: 'validation_failed',
                    safeDetails: ['fields' => array_keys($exception->errors())],
                );
            }

            return response()->json([
                'message' => 'Data yang dikirim belum valid. Periksa kembali isian Anda.',
                'errors' => $exception->errors(),
            ], $exception->status);
        });

        $exceptions->render(function (IntegrationConflictException $exception, Request $request): JsonResponse {
            if (str_starts_with($request->path(), 'api/v1/integrations')) {
                $token = $request->attributes->get('integration_token');
                app(ApplicationIntegrationAuditService::class)->record(
                    request: $request,
                    application: $request->attributes->get('integration_application')
                        ?: (is_object($request->route('application')) ? $request->route('application') : null),
                    token: is_object($token) ? $token : null,
                    operation: 'request.conflict',
                    status: 'failed',
                    externalCustomerId: $request->route('externalId') ?: $request->input('external_customer_id'),
                    errorCode: $exception->safeCode,
                );
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'error_code' => $exception->safeCode,
            ], 409);
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request): JsonResponse {
            return response()->json([
                'message' => 'Sesi Anda telah berakhir. Silakan masuk kembali.',
            ], 401);
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request): JsonResponse {
            return response()->json([
                'message' => 'Anda tidak memiliki izin untuk mengakses halaman ini.',
            ], 403);
        });

        $exceptions->render(function (ModelNotFoundException $exception, Request $request): JsonResponse {
            return response()->json([
                'message' => 'Data yang Anda cari tidak ditemukan.',
            ], 404);
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request): JsonResponse {
            $status = $exception->getStatusCode();
            $message = match ($status) {
                401 => 'Sesi Anda telah berakhir. Silakan masuk kembali.',
                403 => 'Anda tidak memiliki izin untuk mengakses halaman ini.',
                404 => 'Data yang Anda cari tidak ditemukan.',
                429 => 'Terlalu banyak percobaan. Silakan tunggu beberapa saat sebelum mencoba kembali.',
                default => 'Permintaan tidak dapat diproses. Silakan coba kembali.',
            };

            $response = response()->json(['message' => $message], $status);

            if ($status === 429 && str_starts_with($request->path(), 'api/v1/admin/')) {
                Log::warning('Admin API request throttled.', [
                    'route' => $request->route()?->getName() ?: $request->path(),
                    'method' => $request->method(),
                    'admin_id' => $request->user()?->getAuthIdentifier(),
                    'status' => $status,
                    'occurred_at' => now()->toIso8601String(),
                    'retry_after' => $exception->getHeaders()['Retry-After'] ?? null,
                    'rate_limit' => $exception->getHeaders()['X-RateLimit-Limit'] ?? null,
                    'rate_remaining' => $exception->getHeaders()['X-RateLimit-Remaining'] ?? null,
                ]);
            }

            if ($status === 429 && (str_starts_with($request->path(), 'api/v1/integrations')
                || str_contains($request->path(), '/integration-tests'))) {
                $token = $request->attributes->get('integration_token');
                app(ApplicationIntegrationAuditService::class)->record(
                    request: $request,
                    application: $request->attributes->get('integration_application')
                        ?: (is_object($request->route('application')) ? $request->route('application') : null),
                    token: is_object($token) ? $token : null,
                    operation: str_contains($request->path(), '/integration-tests') ? 'test.rate_limit' : 'rate_limit',
                    status: 'failed',
                    externalCustomerId: $request->route('externalId') ?: $request->input('external_customer_id'),
                    errorCode: 'rate_limited',
                    safeDetails: ['retry_after' => $exception->getHeaders()['Retry-After'] ?? null],
                );
            }

            // ThrottleRequests menyediakan Retry-After, tetapi membuat respons
            // JSON baru di sini sebelumnya menghilangkan header tersebut.
            // Teruskan hanya metadata rate-limit yang aman agar klien dapat
            // menghormati masa tunggu tanpa mengekspos header lain.
            if ($status === 429 && method_exists($exception, 'getHeaders')) {
                foreach (['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'] as $header) {
                    $value = $exception->getHeaders()[$header] ?? null;
                    if (is_string($value) || is_numeric($value)) {
                        $response->headers->set($header, (string) $value);
                    }
                }
            }

            return $response;
        });

        $exceptions->render(function (Throwable $exception, Request $request): JsonResponse {
            if (str_starts_with($request->path(), 'api/v1/integrations')
                || str_contains($request->path(), '/integration-tests')) {
                try {
                    $token = $request->attributes->get('integration_token');
                    app(ApplicationIntegrationAuditService::class)->record(
                        request: $request,
                        application: $request->attributes->get('integration_application')
                            ?: (is_object($request->route('application')) ? $request->route('application') : null),
                        token: is_object($token) ? $token : null,
                        operation: 'request.internal',
                        status: 'failed',
                        externalCustomerId: $request->route('externalId') ?: $request->input('external_customer_id'),
                        errorCode: 'internal_error',
                    );
                } catch (Throwable) {
                    // Jangan mengganti respons utama jika audit storage bermasalah.
                }
            }

            return response()->json([
                'message' => 'Layanan sedang mengalami gangguan. Silakan coba kembali.',
            ], 500);
        });
    })->create();
