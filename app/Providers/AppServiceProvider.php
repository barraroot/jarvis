<?php

namespace App\Providers;

use App\Models\User;
use App\Services\DirectAnswerRouter;
use App\Services\MessageRouter;
use App\Support\Settings;
use Illuminate\Cache\RateLimiting\Limit;
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
        // Phase 7 routes every message to a direct answer; the intent router (Phase 12) replaces this binding.
        $this->app->bind(MessageRouter::class, DirectAnswerRouter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('admin', fn (User $user): bool => $user->isAdmin());

        $this->configureRateLimiting();
    }

    /**
     * Login attempts are limited per e-mail + IP pair (US-1.1); messages per
     * operator (US-3.1), with the limit read from the admin settings.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request): Limit {
            $email = mb_strtolower(trim((string) $request->input('email')));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('messages', function (Request $request): Limit {
            return Limit::perMinute(app(Settings::class)->messagesPerMinute())->by($request->user()?->getKey() ?? $request->ip());
        });
    }
}
