<?php

namespace Tests\Feature;

use App\Ai\Agents\IntentClassifier;
use App\Ai\Agents\RuntimeAgent;
use App\Events\Task\TaskUpdated;
use App\Events\Tool\ToolCompleted;
use App\Events\Tool\ToolStarted;
use App\Events\Visualization\VisualizationUpdated;
use App\Jobs\RunTask;
use App\Models\Agent;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Tool;
use App\Models\User;
use App\Models\Visualization;
use App\Models\VisualizationStatus;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Ai;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\StructuredAnonymousAgent;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

/**
 * End-to-end voice flow (T31): CT-01 opens the session, CT-02 starts a task
 * on it, `RunTask` feeds the visualization through the internal tool, the
 * enriched events go out, CT-08 restores it, CT-09 closes it and a follow-up
 * on the closed visualization creates a new one.
 */
class VoiceFlowIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private const string PROVIDER_KEY = 'sk-voice-flow-provider-key-4242';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        Queue::fake();
        Embeddings::fake();
        Event::fake([VisualizationUpdated::class, ToolStarted::class, ToolCompleted::class, TaskUpdated::class]);
        Http::fake([
            '*/realtime/client_secrets' => Http::response(['value' => 'ek_voice_flow', 'expires_at' => now()->addMinute()->timestamp]),
        ]);

        IntentClassifier::fake(fn (): array => ['intent' => 'use_tool', 'agent_slug' => null, 'confidence' => 0.9]);

        $provider = AiProvider::factory()->create(['api_key' => self::PROVIDER_KEY]);
        AiModel::factory()->defaultRealtime()->for($provider, 'provider')->create(['model_identifier' => 'gpt-realtime']);
        AiModel::factory()->defaultText()->for($provider, 'provider')->create(['name' => 'GPT Tarefas']);
        AiModel::factory()->defaultEmbedding()->for($provider, 'provider')->create();

        $this->artisan('jarvis:tools:sync-internal')->assertSuccessful();

        $agent = Agent::factory()->default()->create(['max_tool_calls' => 5]);
        $agent->tools()->attach(Tool::query()->where('identifier', 'visualization.update_section')->firstOrFail()->id);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_voice_flow_from_session_to_follow_up_visualization(): void
    {
        // CT-01: the realtime session and its conversation.
        $session = $this->postJson('/api/v1/voice/sessions', ['locale' => 'pt-BR'])
            ->assertCreated()
            ->assertJsonPath('data.client_secret', 'ek_voice_flow')
            ->json('data');

        $this->assertStringNotContainsString(self::PROVIDER_KEY, (string) json_encode($session));

        // CT-02 with the voice_session_id returned by CT-01.
        $created = $this->postJson('/api/v1/voice/tasks', [
            'voice_session_id' => $session['voice_session_id'],
            'request' => 'Busque no blog a melhor aula de inglês',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', TaskStatus::QUEUED)
            ->assertJsonPath('data.visualization_id', null)
            ->json('data');

        $task = Task::query()->findOrFail($created['id']);
        $this->assertSame($session['voice_session_id'], $task->voice_session_id);
        $this->assertSame($session['conversation_id'], $task->conversation_id);
        Queue::assertPushedOn(RunTask::QUEUE, RunTask::class);

        // RunTask with the fake agent calling visualization.update_section twice.
        $this->runTaskWithVisualization($task, 'Encontrei a aula de inglês para devs.');

        $task->refresh();
        $this->assertSame(TaskStatus::COMPLETED, $task->status->slug);

        $visualization = Visualization::query()->sole();
        $this->assertSame($this->user->id, $visualization->user_id);
        $this->assertSame($visualization->id, $task->visualization_id);
        $this->assertSame(VisualizationStatus::UPDATED, $visualization->status->slug);
        $this->assertSame(['question', 'results'], array_column($visualization->sections, 'key'));

        $this->assertEnrichedToolEvents($task);
        $this->assertVisualizationEvents($visualization->id);

        Event::assertDispatched(TaskUpdated::class, function (TaskUpdated $event) use ($task, $visualization): bool {
            $payload = $event->broadcastWith()['task'];

            return $payload['id'] === $task->id
                && $payload['status'] === TaskStatus::COMPLETED
                && $payload['visualization_id'] === $visualization->id
                && $payload['model'] === ['name' => 'GPT Tarefas']
                && str_starts_with((string) $payload['voice_summary'], '<tool_result name="task" trusted="false">');
        });

        // CT-08 restores it after a reload (RF-19b).
        $this->getJson('/api/v1/visualizations/current')
            ->assertOk()
            ->assertJsonPath('data.id', $visualization->id)
            ->assertJsonPath('data.status', VisualizationStatus::UPDATED)
            ->assertJsonPath('data.closed_at', null)
            ->assertJsonPath('data.sections.1.items.0.title', 'Inglês para devs');

        // CT-09 closes it without touching the task (RF-19a).
        $this->postJson("/api/v1/visualizations/{$visualization->id}/close")
            ->assertOk()
            ->assertJsonPath('data.id', $visualization->id);

        $this->assertNotNull($visualization->fresh()->closed_at);
        $this->assertSame(TaskStatus::COMPLETED, $task->fresh()->status->slug);
        $this->getJson('/api/v1/visualizations/current')->assertExactJson(['data' => null]);

        // A follow-up on the closed visualization gets a brand new one (RF-19).
        $followUp = $this->postJson('/api/v1/voice/tasks', [
            'voice_session_id' => $session['voice_session_id'],
            'request' => 'E a segunda melhor aula?',
            'visualization_id' => $visualization->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.visualization_id', null)
            ->json('data');

        $followUpTask = Task::query()->findOrFail($followUp['id']);
        $this->assertSame($session['conversation_id'], $followUpTask->conversation_id);

        $this->runTaskWithVisualization($followUpTask, 'A segunda melhor é Inglês para entrevistas.');

        $followUpTask->refresh();
        $this->assertSame(TaskStatus::COMPLETED, $followUpTask->status->slug);
        $this->assertSame(2, Visualization::query()->where('user_id', $this->user->id)->count());
        $this->assertNotNull($followUpTask->visualization_id);
        $this->assertNotSame($visualization->id, $followUpTask->visualization_id);
        $this->assertNotNull($visualization->fresh()->closed_at);

        $this->getJson('/api/v1/visualizations/current')
            ->assertOk()
            ->assertJsonPath('data.id', $followUpTask->visualization_id)
            ->assertJsonPath('data.task_id', $followUpTask->id);
    }

    /**
     * Run the task synchronously, as the `tasks` worker would, with the agent
     * creating the question section and then the results.
     */
    private function runTaskWithVisualization(Task $task, string $answer): void
    {
        Ai::fakeAgent(RuntimeAgent::class, [
            new ToolCall('call-question', 'visualization_update_section', [
                'title' => 'Busca no blog · aula de inglês',
                'source' => 'Beer And Code · MCP do blog',
                'section' => ['key' => 'question', 'label' => 'PERGUNTA', 'type' => 'question', 'headline' => 'Qual a melhor aula de inglês?'],
            ]),
            new ToolCall('call-results', 'visualization_update_section', [
                'section' => ['key' => 'results', 'label' => 'RESULTADOS', 'type' => 'results', 'status' => 'updated', 'items' => [
                    ['title' => 'Inglês para devs', 'subtitle' => 'aula 12', 'badges' => ['best']],
                ]],
            ]),
            $answer,
        ]);
        Ai::fakeAgent(StructuredAnonymousAgent::class, fn (): array => ['result' => ['type' => 'text', 'text' => $answer]]);

        (new RunTask($task->fresh()))->handle(app(TaskService::class));
    }

    /** CT-03: `tool.*` carry the task, the display name and the input summary. */
    private function assertEnrichedToolEvents(Task $task): void
    {
        Event::assertDispatchedTimes(ToolStarted::class, 2);

        Event::assertDispatched(ToolStarted::class, function (ToolStarted $event) use ($task): bool {
            $payload = $event->broadcastWith();

            return $event->broadcastAs() === 'tool.started'
                && $event->broadcastOn()->name === 'private-user.'.$this->user->id
                && $payload['tool'] === 'visualization.update_section'
                && $payload['task_id'] === $task->id
                && $payload['agent_execution_id'] !== null
                && is_string($payload['display_name']) && $payload['display_name'] !== ''
                && is_string($payload['input_summary']);
        });

        Event::assertDispatched(ToolCompleted::class, fn (ToolCompleted $event): bool => $event->broadcastWith()['task_id'] === $task->id
            && $event->broadcastWith()['status'] === 'completed'
            && is_int($event->broadcastWith()['output_bytes']));
    }

    /** CT-05: the panel goes from `syncing` to `updated` on the private channel. */
    private function assertVisualizationEvents(int $visualizationId): void
    {
        $statuses = Event::dispatched(VisualizationUpdated::class)
            ->map(fn (array $arguments): array => $arguments[0]->broadcastWith()['visualization'])
            ->filter(fn (array $payload): bool => $payload['id'] === $visualizationId)
            ->pluck('status')
            ->values()
            ->all();

        $this->assertSame([VisualizationStatus::SYNCING, VisualizationStatus::UPDATED], $statuses);

        Event::assertDispatched(
            VisualizationUpdated::class,
            fn (VisualizationUpdated $event): bool => $event->broadcastOn()->name === 'private-user.'.$this->user->id
                && $event->broadcastAs() === 'visualization.updated',
        );
    }
}
