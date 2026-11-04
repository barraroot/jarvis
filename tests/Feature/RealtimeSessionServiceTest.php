<?php

namespace Tests\Feature;

use App\Exceptions\RealtimeProviderException;
use App\Exceptions\RealtimeUnavailableException;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiProviderDriver;
use App\Models\Memory;
use App\Models\User;
use App\Models\VoiceSession;
use App\Services\RealtimeSessionService;
use App\Support\Settings;
use App\Support\UntrustedContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class RealtimeSessionServiceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private const string PROVIDER_KEY = 'sk-realtime-provider-secret-key-0001';

    private User $user;

    private AiProvider $provider;

    private AiModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->user = User::factory()->create();
        $this->provider = AiProvider::factory()->create(['api_key' => self::PROVIDER_KEY]);
        $this->model = AiModel::factory()->defaultRealtime()->for($this->provider, 'provider')->create([
            'model_identifier' => 'gpt-realtime',
        ]);
    }

    public function test_requests_client_secret_with_provider_key_and_60s_ttl(): void
    {
        $expiresAt = now()->addSeconds(60)->timestamp;
        $this->fakeProvider($expiresAt);

        $secret = $this->service()->issue($this->user, $this->voiceSession());

        $this->assertSame('ek_test_secret', $secret->value);
        $this->assertSame($expiresAt, $secret->expiresAt->timestamp);
        $this->assertTrue($secret->model->is($this->model));
        $this->assertSame('marin', $secret->voice);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.openai.com/v1/realtime/client_secrets'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer '.self::PROVIDER_KEY)
                && $request['expires_after'] === ['anchor' => 'created_at', 'seconds' => 60]
                && $request['session']['type'] === 'realtime'
                && $request['session']['model'] === 'gpt-realtime'
                && $request['session']['audio']['output']['voice'] === 'marin'
                && $request['session']['audio']['input']['turn_detection']['type'] === 'server_vad'
                && $request['session']['audio']['input']['turn_detection']['interrupt_response'] === true
                && $request['session']['tools'][0]['name'] === 'start_task'
                && array_keys($request['session']['tools'][0]['parameters']['properties']) === ['request', 'follow_up'];
        });
    }

    public function test_uses_provider_base_url_and_voice_setting(): void
    {
        $this->provider->update(['base_url' => 'https://proxy.example.com/v1/']);
        app(Settings::class)->set('voice.realtime_voice', 'cedar');
        $this->fakeProvider(now()->addMinute()->timestamp);

        $secret = $this->service()->issue($this->user, $this->voiceSession());

        $this->assertSame('cedar', $secret->voice);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://proxy.example.com/v1/realtime/client_secrets'
            && $request['session']['audio']['output']['voice'] === 'cedar');
    }

    public function test_instructions_contain_untrusted_policy(): void
    {
        $this->fakeProvider(now()->addMinute()->timestamp);

        $this->service()->issue($this->user, $this->voiceSession());

        Http::assertSent(fn (Request $request): bool => str_contains($request['session']['instructions'], UntrustedContent::POLICY));
    }

    public function test_instructions_never_contain_user_memories(): void
    {
        $unique = 'MEMORIA-UNICA-'.fake()->uuid();
        Memory::factory()->create(['user_id' => $this->user->id, 'content' => $unique]);
        $this->fakeProvider(now()->addMinute()->timestamp);

        $this->service()->issue($this->user, $this->voiceSession());

        Http::assertSent(fn (Request $request): bool => ! str_contains($request->body(), $unique));
    }

    public function test_missing_realtime_model_throws_unavailable(): void
    {
        Http::fake();
        $this->model->update(['is_default_realtime' => false]);

        $this->expectException(RealtimeUnavailableException::class);
        $this->expectExceptionMessage(__('voice.errors.realtime_unavailable'));

        try {
            $this->service()->issue($this->user, $this->voiceSession());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_provider_without_key_or_non_openai_driver_throws_unavailable(): void
    {
        $this->provider->update(['api_key' => null]);

        try {
            $this->service()->realtimeModel();
            $this->fail('Provider without key must be unavailable.');
        } catch (RealtimeUnavailableException) {
            $this->addToAssertionCount(1);
        }

        $this->provider->update([
            'api_key' => self::PROVIDER_KEY,
            'ai_provider_driver_id' => AiProviderDriver::idFor(AiProviderDriver::ANTHROPIC),
        ]);

        $this->expectException(RealtimeUnavailableException::class);

        $this->service()->realtimeModel();
    }

    public function test_provider_error_throws_provider_exception(): void
    {
        Http::fake([
            '*' => Http::response(['error' => ['message' => 'PROVIDER-BODY-SHOULD-NOT-LEAK']], 401),
        ]);

        try {
            $this->service()->issue($this->user, $this->voiceSession());
            $this->fail('Provider failure must throw.');
        } catch (RealtimeProviderException $exception) {
            $this->assertSame(__('voice.errors.provider_failed'), $exception->getMessage());
            $this->assertStringNotContainsString('PROVIDER-BODY-SHOULD-NOT-LEAK', $exception->getMessage());
            $this->assertStringNotContainsString(self::PROVIDER_KEY, $exception->getMessage());
        }
    }

    public function test_connection_failure_throws_provider_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->expectException(RealtimeProviderException::class);

        $this->service()->issue($this->user, $this->voiceSession());
    }

    private function service(): RealtimeSessionService
    {
        return app(RealtimeSessionService::class);
    }

    private function voiceSession(): VoiceSession
    {
        return VoiceSession::factory()->create([
            'user_id' => $this->user->id,
            'ai_model_id' => $this->model->id,
        ]);
    }

    private function fakeProvider(int $expiresAt): void
    {
        Http::fake([
            '*/realtime/client_secrets' => Http::response([
                'value' => 'ek_test_secret',
                'expires_at' => $expiresAt,
                'session' => ['type' => 'realtime', 'model' => 'gpt-realtime'],
            ]),
        ]);
    }
}
