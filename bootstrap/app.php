<?php

use App\Exceptions\Handler;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // Extended health check (DB, Redis, Reverb) outside the `web` group;
        // public webhooks outside the `api` group (CT-09).
        then: function (): void {
            Route::get('/up', HealthController::class)->name('health');
            Route::group([], __DIR__.'/../routes/webhooks.php');
        },
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'auth:sanctum', 'active']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        // Global per-operator API limit (`api`, 120/min); route-level limiters
        // (`login`, `messages`, `approvals`, `connectors.resync`) stack on top.
        $middleware->throttleApi();

        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'admin' => EnsureUserIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Every limiter answers the same 429 body; `Retry-After` and the
        // `X-RateLimit-*` headers come from the throttle middleware.
        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $headers = $exception->getHeaders();

            return response()->json([
                'message' => __('http.too_many_requests'),
                'retry_after' => (int) ($headers['Retry-After'] ?? 60),
            ], 429, $headers);
        });
    })
    // The custom handler strips `api_key` from exception context (US-5.1).
    // Rebinding happens while the application is registering, before the handler
    // is resolved for the first time, so the `withExceptions` config above applies.
    ->withSingletons([
        ExceptionHandler::class => Handler::class,
    ])
    ->create();
