<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/**
 * Health of the runtime dependencies (observability, US-2.4): database,
 * Redis (cache, queues, sessions) and the Reverb WebSocket server. Each
 * probe is timed; failures are logged with the reason but only reported as
 * `fail` publicly, so the endpoint never leaks infrastructure details.
 */
class HealthCheck
{
    public const string OK = 'ok';

    public const string FAIL = 'fail';

    public const string SKIPPED = 'skipped';

    /**
     * @return array{status: string, components: array<string, array{status: string, latency_ms: int|null}>, failed: list<string>}
     */
    public function run(): array
    {
        $components = [
            'database' => $this->probe('database', fn () => DB::connection()->select('select 1')),
            'redis' => $this->probe('redis', fn () => Redis::connection()->ping()),
            'reverb' => $this->reverbIsUsed()
                ? $this->probe('reverb', fn () => $this->pingReverb())
                : ['status' => self::SKIPPED, 'latency_ms' => null],
        ];

        $failed = array_keys(array_filter(
            $components,
            fn (array $component): bool => $component['status'] === self::FAIL,
        ));

        return [
            'status' => $failed === [] ? self::OK : self::FAIL,
            'components' => $components,
            'failed' => $failed,
        ];
    }

    /**
     * @return array{status: string, latency_ms: int|null}
     */
    private function probe(string $component, Closure $check): array
    {
        $startedAt = microtime(true);

        try {
            $check();

            $status = self::OK;
        } catch (Throwable $exception) {
            Log::warning('Health check failed.', ['component' => $component, 'error' => $exception->getMessage()]);

            $status = self::FAIL;
        }

        return [
            'status' => $status,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    private function reverbIsUsed(): bool
    {
        return config('broadcasting.default') === 'reverb';
    }

    private function pingReverb(): void
    {
        $response = Http::timeout(2)->connectTimeout(2)->get($this->reverbHealthUrl());

        if (! $response->successful()) {
            throw new RuntimeException("Reverb answered {$response->status()}.");
        }
    }

    private function reverbHealthUrl(): string
    {
        $configured = config('services.reverb.health_url');

        if (filled($configured)) {
            return $configured;
        }

        $options = config('broadcasting.connections.reverb.options', []);
        $scheme = $options['scheme'] ?? 'https';
        $port = $options['port'] ?? ($scheme === 'https' ? 443 : 80);

        return "{$scheme}://{$options['host']}:{$port}/up";
    }
}
