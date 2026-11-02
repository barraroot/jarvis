<?php

namespace App\Services;

use App\Models\AiProvider;
use Illuminate\Support\Facades\Cache;

/**
 * Injects database-backed AI providers into the `laravel/ai` runtime configuration (US-5.1).
 *
 * Runs at the start of every request/job that talks to an AI provider, so activating or
 * deactivating a provider in Filament takes effect without a deploy or config change.
 * Soft-deleted and inactive providers are never registered.
 */
class ProviderRegistry
{
    /**
     * How long the list of active providers is cached (seconds).
     */
    public const int CACHE_TTL_SECONDS = 60;

    private const string CACHE_KEY = 'ai-providers-active';

    /**
     * Slugs registered by this class during the current process, so stale entries can be removed.
     *
     * @var array<int, string>
     */
    private static array $managedSlugs = [];

    /**
     * Register every active provider in the runtime config and return the registered entries.
     *
     * @return array<string, array<string, string>>
     */
    public function register(): array
    {
        $registered = [];

        foreach ($this->activeProviderConfigs() as $slug => $config) {
            config(['ai.providers.'.$slug => $config]);

            $registered[$slug] = $config;
        }

        foreach (array_diff(self::$managedSlugs, array_keys($registered)) as $staleSlug) {
            $config = config('ai.providers');

            unset($config[$staleSlug]);

            config(['ai.providers' => $config]);
        }

        self::$managedSlugs = array_keys($registered);

        return $registered;
    }

    /**
     * Resolved provider configs, keyed by slug. Cached as plain arrays so the
     * cached payload never depends on serialized Eloquent models.
     *
     * @return array<string, array<string, string>>
     */
    private function activeProviderConfigs(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            return AiProvider::query()
                ->with('driver')
                ->active()
                ->get()
                ->reject(fn (AiProvider $provider): bool => $provider->driver === null || ! $provider->driver->is_active)
                ->mapWithKeys(function (AiProvider $provider): array {
                    return [$provider->slug => array_filter([
                        'driver' => $provider->driver->slug,
                        'key' => $provider->api_key,
                        'url' => filled($provider->base_url) ? $provider->base_url : null,
                    ], fn (mixed $value): bool => $value !== null)];
                })
                ->all();
        });
    }
}
