<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Conversation;
use App\Models\UsageRecord;
use App\Models\User;
use App\Models\VoiceSession;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class VoiceSessionApiTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private const string PROVIDER_KEY = 'sk-voice-session-provider-key-9999';

    private const string ENDPOINT = '/api/v1/voice/sessions';

    private User $user;

    private AiModel $model;

    private int $providerStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->user = User::factory()->create();
        $provider = AiProvider::factory()->create(['api_key' => self::PROVIDER_KEY]);
        $this->model = AiModel::factory()->defaultRealtime()->for($provider, 'provider')->create([
            'model_identifier' => 'gpt-realtime',
        ]);

        $this->fakeProvider();
        $this->actingAs($this->user);
    }

    public function test_creates_session_and_new_conversation(): void
    {
        $response = $this->postJson(self::ENDPOINT, ['locale' => 'pt-BR']);

        $response->assertCreated()
            ->assertJsonStructure(['data' => [
                'voice_session_id', 'conversation_id', 'client_secret', 'expires_at', 'model', 'voice', 'listening_window_seconds',
            ]])
            ->assertJsonPath('data.client_secret', 'ek_voice_secret')
            ->assertJsonPath('data.model', 'gpt-realtime')
            ->assertJsonPath('data.voice', 'marin')
            ->assertJsonPath('data.listening_window_seconds', 60);

        $this->assertSame(1, Conversation::query()->where('user_id', $this->user->id)->count());

        $session = VoiceSession::query()->sole();
        $this->assertSame($response->json('data.voice_session_id'), $session->id);
        $this->assertSame($response->json('data.conversation_id'), $session->conversation_id);
        $this->assertSame($this->user->id, $session->user_id);
        $this->assertSame($this->model->id, $session->ai_model_id);
        $this->assertNotNull($session->last_issued_at);
        $this->assertNotNull($session->last_activity_at);

        $this->assertStringNotContainsString(self::PROVIDER_KEY, $response->getContent());
    }

    public function test_renewal_reuses_conversation(): void
    {
        $session = VoiceSession::factory()->create([
            'user_id' => $this->user->id,
            'ai_model_id' => $this->model->id,
            'last_issued_at' => now()->subMinutes(10),
            'last_activity_at' => now()->subMinutes(10),
        ]);
        $conversationsBefore = Conversation::query()->count();

        $this->postJson(self::ENDPOINT, ['voice_session_id' => $session->id])
            ->assertCreated()
            ->assertJsonPath('data.voice_session_id', $session->id)
            ->assertJsonPath('data.conversation_id', $session->conversation_id);

        $this->assertSame($conversationsBefore, Conversation::query()->count());
        $this->assertSame(1, VoiceSession::query()->count());

        $session->refresh();
        $this->assertTrue($session->last_issued_at->gt(now()->subMinute()));
        $this->assertTrue($session->last_activity_at->gt(now()->subMinute()));
    }

    public function test_foreign_session_returns_403(): void
    {
        $foreign = VoiceSession::factory()->create(['ai_model_id' => $this->model->id]);

        $this->postJson(self::ENDPOINT, ['voice_session_id' => $foreign->id])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_cost_limit_returns_402_on_create_and_renewal(): void
    {
        $session = VoiceSession::factory()->create([
            'user_id' => $this->user->id,
            'ai_model_id' => $this->model->id,
        ]);
        $this->user->update(['monthly_cost_limit' => 1.00]);
        UsageRecord::factory()->create(['user_id' => $this->user->id, 'estimated_cost' => 2.00]);
        $conversationsBefore = Conversation::query()->count();

        $this->postJson(self::ENDPOINT)
            ->assertStatus(402)
            ->assertJsonPath('message', __('assistant.errors.usage_limit_exceeded'));

        $this->postJson(self::ENDPOINT, ['voice_session_id' => $session->id])->assertStatus(402);

        $this->assertSame($conversationsBefore, Conversation::query()->count());
        Http::assertNothingSent();
    }

    public function test_null_limit_never_blocks(): void
    {
        $this->user->update(['monthly_cost_limit' => null]);
        UsageRecord::factory()->create(['user_id' => $this->user->id, 'estimated_cost' => 9999.00]);

        $this->postJson(self::ENDPOINT)->assertCreated();
    }

    public function test_missing_realtime_model_returns_409(): void
    {
        $this->model->update(['is_default_realtime' => false]);

        $this->postJson(self::ENDPOINT)
            ->assertStatus(409)
            ->assertJsonPath('message', __('voice.errors.realtime_unavailable'));

        $this->assertSame(0, Conversation::query()->count());
        $this->assertSame(0, VoiceSession::query()->count());
    }

    public function test_provider_failure_returns_503(): void
    {
        $this->providerStatus = 500;

        $response = $this->postJson(self::ENDPOINT);

        $response->assertStatus(503)->assertJsonPath('message', __('voice.errors.provider_failed'));
        $this->assertStringNotContainsString('upstream detail', $response->getContent());
        $this->assertSame(0, Conversation::withTrashed()->count());
        $this->assertSame(0, VoiceSession::query()->count());
    }

    public function test_listening_window_reflects_setting(): void
    {
        app(Settings::class)->set('voice.listening_window_seconds', 30);

        $this->postJson(self::ENDPOINT)->assertCreated()->assertJsonPath('data.listening_window_seconds', 30);
    }

    public function test_invalid_payload_returns_422(): void
    {
        $this->postJson(self::ENDPOINT, ['locale' => 'portuguese', 'voice_session_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['locale', 'voice_session_id']);
    }

    public function test_throttled_after_10_per_minute(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson(self::ENDPOINT)->assertCreated();
        }

        $this->postJson(self::ENDPOINT)
            ->assertStatus(429)
            ->assertJsonPath('message', __('http.too_many_requests'));
    }

    private function fakeProvider(): void
    {
        Http::fake([
            '*/realtime/client_secrets' => fn () => $this->providerStatus === 200
                ? Http::response(['value' => 'ek_voice_secret', 'expires_at' => now()->addMinute()->timestamp])
                : Http::response(['error' => ['message' => 'upstream detail']], $this->providerStatus),
        ]);
    }
}
