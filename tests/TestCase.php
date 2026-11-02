<?php

namespace Tests;

use App\Models\LookupModel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        LookupModel::flushCache();

        // The SPA shell uses @vite; tests never depend on a built manifest.
        $this->withoutVite();
    }
}
