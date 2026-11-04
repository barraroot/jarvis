<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Conversation;
use App\Models\ExecutionStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class VoiceSchemaTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    public function test_voice_sessions_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('voice_sessions', [
            'id',
            'user_id',
            'conversation_id',
            'ai_model_id',
            'total_tokens',
            'last_issued_at',
            'last_activity_at',
            'created_at',
            'updated_at',
        ]));

        $sessionId = $this->insertVoiceSession();

        $this->assertSame(0, DB::table('voice_sessions')->where('id', $sessionId)->value('total_tokens'));
    }

    public function test_usage_records_rejects_duplicate_response_id_per_session(): void
    {
        $model = AiModel::factory()->create();
        $sessionId = $this->insertVoiceSession($model);

        $this->insertUsageRecord($model, $sessionId, 'resp_1');
        $this->insertUsageRecord($model, $sessionId, null);
        $this->insertUsageRecord($model, $sessionId, null);
        $this->insertUsageRecord($model, $this->insertVoiceSession($model), 'resp_1');

        $this->assertSame(4, DB::table('usage_records')->count());

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(function () use ($model, $sessionId): void {
            $this->insertUsageRecord($model, $sessionId, 'resp_1');
        });
    }

    public function test_usage_record_link_is_cleared_when_voice_session_is_deleted(): void
    {
        $model = AiModel::factory()->create();
        $sessionId = $this->insertVoiceSession($model);
        $usageId = $this->insertUsageRecord($model, $sessionId, 'resp_1');

        DB::table('voice_sessions')->where('id', $sessionId)->delete();

        $this->assertNull(DB::table('usage_records')->where('id', $usageId)->value('voice_session_id'));
    }

    public function test_only_one_default_realtime_model_allowed(): void
    {
        AiModel::factory()->create(['is_default_realtime' => false]);
        AiModel::factory()->create(['is_default_realtime' => true]);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(function (): void {
            AiModel::factory()->create(['is_default_realtime' => true]);
        });
    }

    public function test_user_preferences_has_consent_version(): void
    {
        $this->assertTrue(Schema::hasColumn('user_preferences', 'wake_word_consent_version'));

        $user = User::factory()->withPreference()->create();

        $this->assertNull(DB::table('user_preferences')->where('user_id', $user->id)->value('wake_word_consent_version'));

        DB::table('user_preferences')->where('user_id', $user->id)->update(['wake_word_consent_version' => 2]);

        $this->assertSame(2, DB::table('user_preferences')->where('user_id', $user->id)->value('wake_word_consent_version'));
    }

    public function test_visualizations_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('visualization_statuses', ['id', 'name', 'slug', 'description', 'is_active']));
        $this->assertTrue(Schema::hasColumns('visualizations', [
            'id',
            'user_id',
            'task_id',
            'visualization_status_id',
            'title',
            'source',
            'sections',
            'closed_at',
            'created_at',
            'updated_at',
        ]));

        $task = Task::factory()->create();
        $visualizationId = $this->insertVisualization($task);

        $this->assertSame([], json_decode(DB::table('visualizations')->where('id', $visualizationId)->value('sections'), true));
        $this->assertNull(DB::table('visualizations')->where('id', $visualizationId)->value('closed_at'));

        $task->delete();

        $this->assertNull(DB::table('visualizations')->where('id', $visualizationId)->value('task_id'));
    }

    public function test_tasks_has_voice_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('tasks', ['visualization_id', 'voice_session_id', 'ai_model_id']));

        $model = AiModel::factory()->create();
        $voiceSessionId = $this->insertVoiceSession($model);
        $visualizationId = $this->insertVisualization(Task::factory()->create());

        $task = Task::factory()->create();
        DB::table('tasks')->where('id', $task->id)->update([
            'visualization_id' => $visualizationId,
            'voice_session_id' => $voiceSessionId,
            'ai_model_id' => $model->id,
        ]);

        DB::table('visualizations')->where('id', $visualizationId)->delete();
        DB::table('voice_sessions')->where('id', $voiceSessionId)->delete();
        DB::table('ai_models')->where('id', $model->id)->delete();

        $row = DB::table('tasks')->where('id', $task->id)->first();

        $this->assertNull($row->visualization_id);
        $this->assertNull($row->voice_session_id);
        $this->assertNull($row->ai_model_id);
    }

    private function insertVisualization(Task $task): int
    {
        $statusId = DB::table('visualization_statuses')->where('slug', 'syncing')->value('id')
            ?? DB::table('visualization_statuses')->insertGetId([
                'name' => 'Syncing',
                'slug' => 'syncing',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        return DB::table('visualizations')->insertGetId([
            'user_id' => $task->user_id,
            'task_id' => $task->id,
            'visualization_status_id' => $statusId,
            'title' => 'Busca no blog',
            'source' => 'MCP do blog',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertVoiceSession(?AiModel $model = null): int
    {
        $user = User::factory()->create();

        return DB::table('voice_sessions')->insertGetId([
            'user_id' => $user->id,
            'conversation_id' => Conversation::factory()->for($user)->create()->id,
            'ai_model_id' => ($model ?? AiModel::factory()->create())->id,
            'last_issued_at' => now(),
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertUsageRecord(AiModel $model, int $voiceSessionId, ?string $responseId): int
    {
        return DB::table('usage_records')->insertGetId([
            'user_id' => DB::table('voice_sessions')->where('id', $voiceSessionId)->value('user_id'),
            'ai_provider_id' => $model->ai_provider_id,
            'ai_model_id' => $model->id,
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::COMPLETED),
            'voice_session_id' => $voiceSessionId,
            'provider_response_id' => $responseId,
            'input_tokens' => 10,
            'output_tokens' => 5,
            'created_at' => now(),
        ]);
    }
}
