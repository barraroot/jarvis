<?php

namespace Tests\Feature;

use App\Support\HealthCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.options.host' => 'reverb.internal',
            'broadcasting.connections.reverb.options.port' => 8080,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'services.reverb.health_url' => null,
        ]);
    }

    public function test_reports_ok_when_every_component_answers(): void
    {
        Http::fake(['http://reverb.internal:8080/up' => Http::response('{"health":"ok"}')]);

        $this->getJson('/up')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('status', HealthCheck::OK)
            ->assertJsonPath('components.database.status', HealthCheck::OK)
            ->assertJsonPath('components.redis.status', HealthCheck::OK)
            ->assertJsonPath('components.reverb.status', HealthCheck::OK)
            ->assertJsonPath('failed', []);

        Http::assertSent(fn ($request): bool => $request->url() === 'http://reverb.internal:8080/up');
    }

    public function test_reports_503_when_redis_is_unavailable(): void
    {
        Http::fake(['*' => Http::response('{"health":"ok"}')]);

        $connection = Mockery::mock();
        $connection->shouldReceive('ping')->andThrow(new RuntimeException('Connection refused [tcp://redis:6379]'));

        Redis::shouldReceive('connection')->andReturn($connection);

        $response = $this->getJson('/up');

        $response->assertStatus(503)
            ->assertJsonPath('status', HealthCheck::FAIL)
            ->assertJsonPath('components.redis.status', HealthCheck::FAIL)
            ->assertJsonPath('components.database.status', HealthCheck::OK)
            ->assertJsonPath('components.reverb.status', HealthCheck::OK)
            ->assertJsonPath('failed', ['redis']);

        // The failure reason is logged, never exposed.
        $this->assertStringNotContainsString('Connection refused', (string) $response->getContent());
    }

    public function test_reports_503_when_reverb_does_not_answer(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('Connection timed out')]);

        $this->getJson('/up')
            ->assertStatus(503)
            ->assertJsonPath('components.reverb.status', HealthCheck::FAIL)
            ->assertJsonPath('failed', ['reverb']);
    }

    public function test_reverb_is_skipped_when_broadcasting_does_not_use_it(): void
    {
        config(['broadcasting.default' => 'null']);
        Http::fake();

        $this->getJson('/up')
            ->assertOk()
            ->assertJsonPath('components.reverb.status', HealthCheck::SKIPPED);

        Http::assertNothingSent();
    }
}
