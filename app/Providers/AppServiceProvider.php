<?php

namespace App\Providers;

use App\Models\User;
use App\Services\IntentRouter;
use App\Services\MessageRouter;
use App\Support\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Phase 12 classifies each message and routes it to a direct answer,
        // a tool/agent run or (Phase 13) a long task (US-3.3).
        $this->app->bind(MessageRouter::class, IntentRouter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('admin', fn (User $user): bool => $user->isAdmin());

        // N+1 guard: lazy loading inside collections throws outside production.
        Model::preventLazyLoading(! $this->app->isProduction());

        // Note: `ExecuteApprovedTool` is wired by Laravel's event discovery
        // (app/Listeners); registering it here again would run it twice.

        $this->configureRateLimiting();
    }

    /**
     * Every API call is limited per operator (`api`, 120/min); login attempts
     * per e-mail + IP pair (US-1.1); messages per operator (US-3.1), with the
     * limit read from the admin settings; approval decisions per operator and
     * connector resyncs per connector. Exceeding any of them answers a
     * standardized `429` with `Retry-After` (see bootstrap/app.php).
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(120)->by($request->user()?->getKey() ?? $request->ip());
        });

        RateLimiter::for('login', function (Request $request): Limit {
            $email = mb_strtolower(trim((string) $request->input('email')));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('messages', function (Request $request): Limit {
            return Limit::perMinute(app(Settings::class)->messagesPerMinute())->by($request->user()?->getKey() ?? $request->ip());
        });

        RateLimiter::for('approvals', function (Request $request): Limit {
            return Limit::perMinute(30)->by($request->user()?->getKey() ?? $request->ip());
        });

        RateLimiter::for('connectors.resync', function (Request $request): Limit {
            $connector = $request->route('connector');

            return Limit::perMinute(6)->by(is_object($connector) ? $connector->getKey() : (string) $connector);
        });
    }
}
