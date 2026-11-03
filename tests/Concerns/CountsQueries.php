<?php

namespace Tests\Concerns;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Query budget assertions for N+1 regressions (performance).
 */
trait CountsQueries
{
    /**
     * Run the callback and return how many SQL statements it executed.
     */
    protected function countQueries(Closure $callback): int
    {
        $count = 0;

        DB::listen(function () use (&$count): void {
            $count++;
        });

        $callback();

        DB::flushQueryLog();

        return $count;
    }

    protected function assertQueryCountLessThan(int $maximum, Closure $callback, string $message = ''): int
    {
        $count = $this->countQueries($callback);

        $this->assertLessThan($maximum, $count, $message ?: "Executed {$count} queries; budget is below {$maximum}.");

        return $count;
    }
}
