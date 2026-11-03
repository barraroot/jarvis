<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Conversation;
use App\Models\LookupModel;
use App\Models\McpServer;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class RateLimitsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        Queue::fake();
        Event::fake();

        $this->user = User::factory()->create(['email' => 'operador@example.com']);
    }

    public function test_each_limiter_returns_429_after_threshold(): void
    {
        $this->assertLoginLimiter();
        $this->assertApiLimiter();
        $this->assertMessagesLimiter();
        $this->assertApprovalsLimiter();
        $this->assertConnectorResyncLimiter();
    }

    /**
     * `login`: 5 attempts per minute for the same e-mail + IP.
     */
    private function assertLoginLimiter(): void
    {
        $this->resetLimiters();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'operador@example.com', 'password' => 'errada'])
                ->assertStatus(422);
        }

        $this->assertStandardized429(
            $this->postJson('/api/v1/auth/login', ['email' => 'operador@example.com', 'password' => 'errada']),
        );
    }

    /**
     * `api`: 120 requests per minute per operator, across every endpoint.
     */
    private function assertApiLimiter(): void
    {
        $this->resetLimiters();
        $this->actingAs($this->user);

        for ($attempt = 1; $attempt <= 120; $attempt++) {
            $this->getJson('/api/v1/auth/me')->assertOk();
        }

        $this->assertStandardized429($this->getJson('/api/v1/auth/me'));

        // Another operator has an independent budget.
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/v1/auth/me')->assertOk();
    }

    /**
     * `messages`: the per-minute limit comes from the admin settings.
     */
    private function assertMessagesLimiter(): void
    {
        $this->resetLimiters();
        $this->actingAs($this->user);

        app(Settings::class)->set('brain.messages_per_minute', 3);

        $conversation = Conversation::factory()->for($this->user)->create();
        $endpoint = "/api/v1/conversations/{$conversation->id}/messages";

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->postJson($endpoint, ['content' => 'Mensagem '.$attempt, 'source' => 'text'])->assertAccepted();
        }

        $this->assertStandardized429($this->postJson($endpoint, ['content' => 'Mensagem 4', 'source' => 'text']));
    }

    /**
     * `approvals`: 30 decisions per minute per operator.
     */
    private function assertApprovalsLimiter(): void
    {
        $this->resetLimiters();
        $this->actingAs($this->user);

        $approval = Approval::factory()->create();

        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $endpoint = $attempt % 2 === 0 ? 'approve' : 'deny';

            $this->assertNotSame(429, $this->postJson("/api/v1/approvals/{$approval->id}/{$endpoint}")->status());
        }

        $this->assertStandardized429($this->postJson("/api/v1/approvals/{$approval->id}/approve"));
    }

    /**
     * `connectors.resync`: 6 resyncs per minute for each connector.
     */
    private function assertConnectorResyncLimiter(): void
    {
        $this->resetLimiters();
        $this->actingAs($this->user);

        $connector = McpServer::factory()->for($this->user)->create();
        $otherConnector = McpServer::factory()->for($this->user)->create();

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->postJson("/api/v1/connectors/{$connector->id}/resync")->assertAccepted();
        }

        $this->assertStandardized429($this->postJson("/api/v1/connectors/{$connector->id}/resync"));

        $this->postJson("/api/v1/connectors/{$otherConnector->id}/resync")->assertAccepted();
    }

    /**
     * Limiter counters live in Redis with real TTLs (time travel does not
     * expire them), so each scenario starts from an empty store.
     */
    private function resetLimiters(): void
    {
        Cache::flush();
        LookupModel::flushCache();
    }

    private function assertStandardized429(TestResponse $response): void
    {
        $response->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', __('http.too_many_requests'))
            ->assertJsonPath('retry_after', fn (int $seconds): bool => $seconds > 0 && $seconds <= 60);
    }
}
