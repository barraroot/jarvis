<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\UsageRecord;
use App\Models\User;
use App\Models\VoiceSession;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class VoiceUsageApiTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    private AiModel $model;

    private VoiceSession $voiceSession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->user = User::factory()->create();
        $this->model = AiModel::factory()->defaultRealtime()->create([
            'input_cost_per_million' => 4.0,
            'output_cost_per_million' => 16.0,
        ]);
        $this->voiceSession = VoiceSession::factory()->create([
            'user_id' => $this->user->id,
            'ai_model_id' => $this->model->id,
            'last_activity_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($this->user);
    }

    public function test_valid_report_creates_usage_record_for_realtime_model(): void
    {
        $this->report('resp_001', 1_000, 500, [
            'input_token_details' => ['cached_tokens' => 0, 'text_tokens' => 200, 'audio_tokens' => 800],
            'output_token_details' => ['text_tokens' => 100, 'audio_tokens' => 400],
        ])
            ->assertCreated()
            ->assertExactJson(['data' => ['session_total_tokens' => 1_500, 'session_token_ceiling' => 200_000]]);

        $record = UsageRecord::query()->sole();
        $this->assertSame($this->user->id, $record->user_id);
        $this->assertSame($this->model->id, $record->ai_model_id);
        $this->assertSame($this->model->ai_provider_id, $record->ai_provider_id);
        $this->assertSame($this->voiceSession->id, $record->voice_session_id);
        $this->assertSame('resp_001', $record->provider_response_id);
        $this->assertSame(1_000, $record->input_tokens);
        $this->assertSame(500, $record->output_tokens);

        $this->voiceSession->refresh();
        $this->assertSame(1_500, $this->voiceSession->total_tokens);
        $this->assertTrue($this->voiceSession->last_activity_at->gt(now()->subMinute()));
    }

    public function test_duplicate_response_id_is_ignored(): void
    {
        $this->report('resp_dup', 100, 50)->assertCreated();

        $this->report('resp_dup', 100, 50)
            ->assertCreated()
            ->assertJsonPath('data.session_total_tokens', 150);

        $this->assertSame(1, UsageRecord::query()->count());
        $this->assertSame(150, $this->voiceSession->fresh()->total_tokens);
    }

    public function test_report_exceeding_ceiling_returns_422_and_does_not_write(): void
    {
        app(Settings::class)->set('voice.session_token_ceiling', 10_000);
        $this->voiceSession->update(['total_tokens' => 9_000]);

        $this->report('resp_big', 800, 300)
            ->assertUnprocessable()
            ->assertJsonPath('message', __('voice.errors.session_token_ceiling_exceeded'));

        $this->assertSame(0, UsageRecord::query()->count());
        $this->assertSame(9_000, $this->voiceSession->fresh()->total_tokens);
    }

    public function test_foreign_session_returns_403(): void
    {
        $foreign = VoiceSession::factory()->create(['ai_model_id' => $this->model->id]);

        $this->postJson("/api/v1/voice/sessions/{$foreign->id}/usage", [
            'response_id' => 'resp_x',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])->assertForbidden();

        $this->assertSame(0, UsageRecord::query()->count());
    }

    public function test_negative_or_float_tokens_return_422(): void
    {
        $this->report('resp_neg', -1, 10)->assertUnprocessable()->assertJsonValidationErrors(['usage.input_tokens']);
        $this->report('resp_float', 10, 1.5)->assertUnprocessable()->assertJsonValidationErrors(['usage.output_tokens']);
        $this->report('resp_detail', 10, 10, ['input_token_details' => ['audio_tokens' => -5]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['usage.input_token_details.audio_tokens']);

        $this->assertSame(0, UsageRecord::query()->count());
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function report(string $responseId, int|float $input, int|float $output, array $details = []): TestResponse
    {
        return $this->postJson("/api/v1/voice/sessions/{$this->voiceSession->id}/usage", [
            'response_id' => $responseId,
            'usage' => ['input_tokens' => $input, 'output_tokens' => $output, ...$details],
        ]);
    }
}
