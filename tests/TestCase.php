<?php

namespace Tests;

use App\Models\LookupModel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        LookupModel::flushCache();

        // The cache store is shared across tests in the same process; stale entries must not leak.
        Cache::flush();

        // The SPA shell uses @vite; tests never depend on a built manifest.
        $this->withoutVite();
    }
}
