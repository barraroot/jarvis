<?php

namespace Tests\Feature;

use App\Ai\Agents\RuntimeAgent;
use App\Events\Visualization\VisualizationUpdated;
use App\Jobs\RunTask;
use App\Models\Agent;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\ExecutionStatus;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Tool;
use App\Models\ToolExecution;
use App\Models\User;
use App\Models\Visualization;
use App\Models\VisualizationStatus;
use App\Models\VoiceSession;
use App\Services\TaskContext;
use App\Services\TaskService;
use App\Tools\ToolContext;
use App\Tools\ToolExecutor;
use App\Tools\ToolResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Ai;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\StructuredAnonymousAgent;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class VisualizationToolTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    private Agent $agent;

    private Tool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $provider = AiProvider::factory()->create();
        AiModel::factory()->defaultText()->for($provider, 'provider')->create();
        AiModel::factory()->defaultEmbedding()->for($provider, 'provider')->create();
        Embeddings::fake();

        $this->artisan('jarvis:tools:sync-internal')->assertSuccessful();

        $this->tool = Tool::query()->where('identifier', 'visualization.update_section')->firstOrFail();

        $this->user = User::factory()->create();
        $this->agent = Agent::factory()->create(['max_tool_calls' => 5]);
        $this->agent->tools()->attach($this->tool->id);
    }

    public function test_tool_runs_without_approval(): void
    {
        Event::fake([VisualizationUpdated::class]);
        Queue::fake();

        $task = $this->voiceTask();

        $result = $this->executeInTask($task, [
            'title' => 'Busca no blog',
            'section' => ['key' => 'question', 'label' => 'PERGUNTA', 'type' => 'question', 'headline' => 'Qual aula?'],
        ]);

        $this->assertTrue($result->isCompleted(), (string) $result->error);
        $this->assertDatabaseCount('approvals', 0);
        $this->assertNotSame(TaskStatus::WAITING_APPROVAL, $task->fresh()->status->slug);

        $visualization = Visualization::query()->sole();
        $this->assertSame($this->user->id, $visualization->user_id);
        $this->assertSame(['visualization_id' => $visualization->id, 'section_key' => 'question', 'position' => 1], $result->output);
        Event::assertDispatched(VisualizationUpdated::class);
    }

    public function test_tool_rejects_input_outside_closed_schema(): void
    {
        Event::fake([VisualizationUpdated::class]);
        Queue::fake();

        $task = $this->voiceTask();

        $this->assertThrows(fn () => $this->executeInTask($task, [
            'visualization_id' => 999,
            'section' => ['key' => 'question', 'label' => 'PERGUNTA', 'type' => 'question'],
        ]));

        $this->assertDatabaseCount('visualizations', 0);
    }

    public function test_task_without_tool_call_creates_no_visualization(): void
    {
        Event::fake([VisualizationUpdated::class]);
        Queue::fake();

        $task = $this->voiceTask(['task_status_id' => TaskStatus::idFor(TaskStatus::QUEUED), 'started_at' => null]);

        Ai::fakeAgent(RuntimeAgent::class, ['Resposta direta, sem painel.']);
        Ai::fakeAgent(StructuredAnonymousAgent::class, fn (): array => ['result' => ['type' => 'text', 'text' => 'Resposta direta, sem painel.']]);

        (new RunTask($task))->handle(app(TaskService::class));

        $task->refresh();
        $this->assertSame(TaskStatus::COMPLETED, $task->status->slug);
        $this->assertNull($task->visualization_id);
        $this->assertDatabaseCount('visualizations', 0);
        Event::assertNotDispatched(VisualizationUpdated::class);
    }

    public function test_cannot_touch_other_users_visualization(): void
    {
        Event::fake([VisualizationUpdated::class]);
        Queue::fake();

        $other = User::factory()->create();
        $foreign = Visualization::factory()->for($other)->create();
        $foreignSections = $foreign->sections;

        // Even a task wrongly linked to a foreign visualization writes only its own.
        $task = $this->voiceTask(['visualization_id' => $foreign->id]);

        $result = $this->executeInTask($task, [
            'section' => ['key' => 'question', 'label' => 'PERGUNTA', 'type' => 'question', 'headline' => 'Invasão'],
        ]);

        $this->assertTrue($result->isCompleted(), (string) $result->error);

        $foreign->refresh();
        $this->assertEquals($foreignSections, $foreign->sections);
        $this->assertSame($other->id, $foreign->user_id);

        $own = Visualization::query()->where('user_id', $this->user->id)->sole();
        $this->assertSame('Invasão', $own->sections[0]['headline']);
        $this->assertSame($own->id, $task->fresh()->visualization_id);

        // A task of another operator can never run under this user's context.
        $foreignTask = Task::factory()->running()->for($other)->create([
            'voice_session_id' => VoiceSession::factory()->for($other)->create()->id,
        ]);

        $failed = $this->executeInTask($foreignTask, [
            'section' => ['key' => 'question', 'label' => 'PERGUNTA', 'type' => 'question', 'headline' => 'x'],
        ]);

        $this->assertFalse($failed->isCompleted());
        $this->assertSame(1, Visualization::query()->where('user_id', $this->user->id)->count());
        $this->assertSame(1, Visualization::query()->where('user_id', $other->id)->count());
    }

    public function test_run_task_end_to_end_marks_visualization_updated(): void
    {
        Event::fake([VisualizationUpdated::class]);
        Queue::fake();

        $task = $this->voiceTask(['task_status_id' => TaskStatus::idFor(TaskStatus::QUEUED), 'started_at' => null]);

        Ai::fakeAgent(RuntimeAgent::class, [
            new ToolCall('call-question', 'visualization_update_section', [
                'title' => 'Busca no blog · aula de inglês',
                'source' => 'Beer And Code · MCP do blog',
                'section' => ['key' => 'question', 'label' => 'PERGUNTA', 'type' => 'question', 'headline' => 'Qual aula?'],
            ]),
            new ToolCall('call-results', 'visualization_update_section', [
                'section' => ['key' => 'results', 'label' => 'RESULTADOS', 'type' => 'results', 'status' => 'updated', 'items' => [
                    ['title' => 'Inglês para devs', 'subtitle' => 'aula 12', 'badges' => ['best']],
                ]],
            ]),
            'Encontrei a aula de inglês para devs.',
        ]);
        Ai::fakeAgent(StructuredAnonymousAgent::class, fn (): array => ['result' => ['type' => 'text', 'text' => 'Encontrei a aula de inglês para devs.']]);

        (new RunTask($task))->handle(app(TaskService::class));

        $task->refresh();
        $this->assertSame(TaskStatus::COMPLETED, $task->status->slug);
        $this->assertSame(0, ToolExecution::query()->where('execution_status_id', '!=', ExecutionStatus::idFor(ExecutionStatus::COMPLETED))->count());

        $visualization = Visualization::query()->sole();
        $this->assertSame($visualization->id, $task->visualization_id);
        $this->assertSame($task->id, $visualization->task_id);
        $this->assertSame(VisualizationStatus::UPDATED, $visualization->status->slug);
        $this->assertSame(['question', 'results'], array_column($visualization->sections, 'key'));
        $this->assertSame('Inglês para devs', $visualization->sections[0]['headline']);
        $this->assertDatabaseCount('approvals', 0);

        Event::assertDispatched(
            VisualizationUpdated::class,
            fn (VisualizationUpdated $event): bool => $event->broadcastWith()['visualization']['status'] === VisualizationStatus::UPDATED,
        );
    }

    /**
     * Execute the tool through the Tool Engine inside a bound task context,
     * as `RunTask` does.
     *
     * @param  array<string, mixed>  $input
     */
    private function executeInTask(Task $task, array $input): ToolResult
    {
        app()->instance(TaskContext::class, app(TaskContext::class, ['task' => $task->fresh(), 'resumeFromStep' => 0]));

        return app(ToolExecutor::class)->execute($this->tool, $input, new ToolContext($this->user, task: $task));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function voiceTask(array $attributes = []): Task
    {
        $session = VoiceSession::factory()->for($this->user)->create();

        return Task::factory()->running()->for($this->user)->for($this->agent)->create([
            'voice_session_id' => $session->id,
            'conversation_id' => $session->conversation_id,
            ...$attributes,
        ]);
    }
}
