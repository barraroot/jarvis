<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\ExecutionStatus;
use App\Models\UsageRecord;
use App\Models\User;
use App\Models\VoiceSession;
use App\Services\UsageRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class UsageRecorderTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();
    }

    public function test_cost_is_calculated_from_model_rates(): void
    {
        $model = AiModel::factory()->create([
            'input_cost_per_million' => 5.0,
            'output_cost_per_million' => 10.0,
        ]);

        $record = app(UsageRecorder::class)->record(
            User::factory()->create(),
            $model,
            inputTokens: 1_000_000,
            outputTokens: 2_000_000,
            latencyMs: 1234,
            statusSlug: ExecutionStatus::COMPLETED,
        );

        $this->assertDatabaseHas('usage_records', [
            'id' => $record->id,
            'user_id' => $record->user_id,
            'ai_provider_id' => $model->ai_provider_id,
            'ai_model_id' => $model->id,
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::COMPLETED),
            'input_tokens' => 1_000_000,
            'output_tokens' => 2_000_000,
            'latency_ms' => 1234,
        ]);

        // 1M input * $5/M + 2M output * $10/M = $25.
        $this->assertSame('25.000000', $record->estimated_cost);
    }

    public function test_failed_attempt_is_recorded_with_its_status_and_zero_cost(): void
    {
        $model = AiModel::factory()->create([
            'input_cost_per_million' => 5.0,
            'output_cost_per_million' => 10.0,
        ]);

        $record = app(UsageRecorder::class)->record(
            User::factory()->create(),
            $model,
            inputTokens: 0,
            outputTokens: 0,
            latencyMs: 800,
            statusSlug: ExecutionStatus::RATE_LIMITED,
        );

        $this->assertSame(ExecutionStatus::idFor(ExecutionStatus::RATE_LIMITED), $record->execution_status_id);
        $this->assertSame('0.000000', $record->estimated_cost);
        $this->assertInstanceOf(UsageRecord::class, $record);
    }

    public function test_voice_session_and_provider_response_id_are_recorded(): void
    {
        $model = AiModel::factory()->realtime()->create();
        $voiceSession = VoiceSession::factory()->create(['ai_model_id' => $model->id]);

        $record = app(UsageRecorder::class)->record(
            $voiceSession->user,
            $model,
            inputTokens: 300,
            outputTokens: 120,
            latencyMs: 0,
            statusSlug: ExecutionStatus::COMPLETED,
            voiceSession: $voiceSession,
            providerResponseId: 'resp_abc123',
        );

        $this->assertDatabaseHas('usage_records', [
            'id' => $record->id,
            'user_id' => $voiceSession->user_id,
            'ai_model_id' => $model->id,
            'voice_session_id' => $voiceSession->id,
            'provider_response_id' => 'resp_abc123',
        ]);
    }

    public function test_voice_columns_default_to_null(): void
    {
        $record = app(UsageRecorder::class)->record(
            User::factory()->create(),
            AiModel::factory()->create(),
            inputTokens: 1,
            outputTokens: 1,
            latencyMs: 10,
            statusSlug: ExecutionStatus::COMPLETED,
        );

        $this->assertNull($record->voice_session_id);
        $this->assertNull($record->provider_response_id);
    }
}
