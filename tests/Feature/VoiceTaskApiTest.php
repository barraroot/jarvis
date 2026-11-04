<?php

namespace Tests\Feature;

use App\Ai\Agents\IntentClassifier;
use App\Jobs\RunTask;
use App\Models\Agent;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\UsageRecord;
use App\Models\User;
use App\Models\Visualization;
use App\Models\VoiceSession;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class VoiceTaskApiTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private const string ENDPOINT = '/api/v1/voice/tasks';

    private User $user;

    private Agent $defaultAgent;

    private AiModel $textModel;

    private VoiceSession $voiceSession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        Queue::fake();

        IntentClassifier::fake(fn (): array => ['intent' => 'use_tool', 'agent_slug' => null, 'confidence' => 0.9]);

        $provider = AiProvider::factory()->create();
        $this->textModel = AiModel::factory()->defaultText()->for($provider, 'provider')->create();
        $this->defaultAgent = Agent::factory()->default()->create();

        $this->user = User::factory()->create();
        $this->voiceSession = VoiceSession::factory()->for($this->user)->create([
            'last_activity_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($this->user);
    }

    public function test_creates_exactly_one_queued_task_for_owner(): void
    {
        $this->storeTask(['request' => 'Busque no blog a aula de inglês'])
            ->assertCreated()
            ->assertJsonPath('data.status', TaskStatus::QUEUED)
            ->assertJsonPath('data.request', 'Busque no blog a aula de inglês')
            ->assertJsonPath('data.agent.slug', $this->defaultAgent->slug);

        $task = Task::query()->sole();

        $this->assertSame($this->user->id, $task->user_id);
        $this->assertSame(TaskStatus::QUEUED, $task->status->slug);
        $this->assertSame($this->voiceSession->conversation_id, $task->conversation_id);
        $this->assertSame($this->voiceSession->id, $task->voice_session_id);
        $this->assertSame($this->textModel->id, $task->ai_model_id);
        $this->assertTrue($this->voiceSession->fresh()->last_activity_at->isAfter(now()->subMinute()));

        Queue::assertPushedOn(RunTask::QUEUE, RunTask::class);
    }

    public function test_task_links_to_given_session_conversation_not_most_recent(): void
    {
        $newer = VoiceSession::factory()->for($this->user)->create();

        $this->storeTask(['request' => 'Pesquise o preço do dólar'])->assertCreated();

        $task = Task::query()->sole();

        $this->assertSame($this->voiceSession->conversation_id, $task->conversation_id);
        $this->assertNotSame($newer->conversation_id, $task->conversation_id);
    }

    public function test_missing_voice_session_id_returns_422(): void
    {
        $this->postJson(self::ENDPOINT, ['request' => 'Busque algo'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('voice_session_id');

        $this->postJson(self::ENDPOINT, ['voice_session_id' => 'abc', 'request' => 'Busque algo'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('voice_session_id');

        $this->storeTask(['request' => ''])->assertUnprocessable()->assertJsonValidationErrors('request');
        $this->storeTask(['request' => str_repeat('a', 4001)])->assertUnprocessable()->assertJsonValidationErrors('request');

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_foreign_or_missing_voice_session_returns_404_and_creates_no_task(): void
    {
        $foreign = VoiceSession::factory()->create();

        $this->storeTask(['voice_session_id' => $foreign->id])->assertNotFound();
        $this->storeTask(['voice_session_id' => $foreign->id + 1000])->assertNotFound();

        $this->assertDatabaseCount('tasks', 0);
        Queue::assertNothingPushed();
    }

    public function test_inactive_voice_session_returns_422_and_creates_no_task(): void
    {
        $idle = VoiceSession::factory()->for($this->user)->inactive()->create();

        $this->storeTask(['voice_session_id' => $idle->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['voice_session_id' => __('voice.session_inactive')]);

        app(Settings::class)->set('voice.session_token_ceiling', 1_000);
        $this->voiceSession->update(['total_tokens' => 1_000]);

        $this->storeTask()
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['voice_session_id' => __('voice.session_inactive')]);

        $this->assertDatabaseCount('tasks', 0);
        Queue::assertNothingPushed();
    }

    public function test_follow_up_links_open_visualization(): void
    {
        $visualization = Visualization::factory()->for($this->user)->create();

        $this->storeTask(['request' => 'Me dá o link', 'visualization_id' => $visualization->id])
            ->assertCreated()
            ->assertJsonPath('data.visualization_id', $visualization->id)
            ->assertJsonPath('data.model', ['name' => $this->textModel->name])
            ->assertJsonPath('data.voice_summary', null);

        $this->assertSame(
            $visualization->id,
            Task::query()->where('voice_session_id', $this->voiceSession->id)->sole()->visualization_id,
        );
    }

    public function test_closed_visualization_is_not_linked(): void
    {
        $visualization = Visualization::factory()->for($this->user)->closed()->create();

        $this->storeTask(['request' => 'Me dá o link', 'visualization_id' => $visualization->id])
            ->assertCreated()
            ->assertJsonPath('data.visualization_id', null);

        $this->assertNull(Task::query()->where('voice_session_id', $this->voiceSession->id)->sole()->visualization_id);
    }

    public function test_foreign_visualization_returns_403(): void
    {
        $visualization = Visualization::factory()->create();
        $tasksBefore = Task::query()->count();

        $this->storeTask(['visualization_id' => $visualization->id])->assertForbidden();

        $this->assertSame($tasksBefore, Task::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_cost_limit_returns_402(): void
    {
        $this->user->update(['monthly_cost_limit' => 1.00]);
        UsageRecord::factory()->create(['user_id' => $this->user->id, 'estimated_cost' => 2.00]);

        $this->storeTask()
            ->assertStatus(402)
            ->assertJsonPath('message', __('assistant.errors.usage_limit_exceeded'));

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_existing_running_task_is_not_cancelled(): void
    {
        $running = Task::factory()->for($this->user)->running()->create([
            'voice_session_id' => $this->voiceSession->id,
            'conversation_id' => $this->voiceSession->conversation_id,
        ]);

        $this->storeTask(['request' => 'Conta uma piada enquanto isso'])->assertCreated();

        $this->assertSame(TaskStatus::RUNNING, $running->fresh()->status->slug);
        $this->assertSame(2, Task::query()->where('voice_session_id', $this->voiceSession->id)->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function storeTask(array $overrides = []): TestResponse
    {
        return $this->postJson(self::ENDPOINT, [
            'voice_session_id' => $this->voiceSession->id,
            'request' => 'Busque no blog a aula de inglês',
            ...$overrides,
        ]);
    }
}
