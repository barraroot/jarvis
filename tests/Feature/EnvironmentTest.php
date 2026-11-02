<?php

namespace Tests\Feature;

use Tests\TestCase;

class EnvironmentTest extends TestCase
{
    public function test_queue_cache_and_broadcast_use_redis_and_reverb(): void
    {
        $this->assertSame('redis', config('queue.default'));
        $this->assertSame('redis', config('cache.default'));
        $this->assertSame('reverb', config('broadcasting.default'));
    }
}
