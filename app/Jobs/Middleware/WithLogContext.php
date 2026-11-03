<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Structured log context for a queued job (observability): every log line
 * written while the job runs carries the job's identifiers (`user_id`,
 * `conversation_id`, `task_id`, ...). The keys are removed afterwards so a
 * long-lived worker never leaks one job's context into the next.
 */
class WithLogContext
{
    /**
     * @param  array<string, int|string|null>  $context
     */
    public function __construct(public readonly array $context) {}

    public function handle(object $job, Closure $next): mixed
    {
        $context = array_filter($this->context, fn (mixed $value): bool => $value !== null);

        Log::withContext($context);

        try {
            return $next($job);
        } finally {
            Log::withoutContext(array_keys($this->context));
        }
    }
}
