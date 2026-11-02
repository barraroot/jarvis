<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VectorExtensionTest extends TestCase
{
    use RefreshDatabase;

    public function test_vector_extension_is_available(): void
    {
        $extensions = DB::table('pg_extension')->pluck('extname')->all();

        $this->assertContains('vector', $extensions);
    }
}
