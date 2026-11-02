<?php

namespace Tests\Concerns;

use App\Models\LookupModel;
use Database\Seeders\LookupSeeder;

/**
 * Seeds every lookup table before each test and resets the in-memory slug cache.
 * Must be combined with RefreshDatabase.
 */
trait SeedsLookups
{
    protected function setUpSeedsLookups(): void
    {
        $this->seed(LookupSeeder::class);

        LookupModel::flushCache();
    }
}
