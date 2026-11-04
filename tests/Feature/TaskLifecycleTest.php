<?php

namespace Tests\Feature;

use App\Events\NotificationCreated;
use App\Events\Task\TaskUpdated;
use App\Jobs\RunTask;
use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\AiModel;
use App\Models\Conversation;
use App\Models\ExecutionStatus;
use App\Models\LogLevel;
use App\Models\Message;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Visualization;
use App\Models\VoiceSession;
use App\Services\AgentRunner;
use App\Services\AgentRuntimeFactory;
use App\Services\ProviderRegistry;
use App\Services\RunOptions;
use App\Services\StructuredResultExtractor;
use App\Services\TaskContext;
use App\Services\TaskService;
use App\Services\UsageRecorder;
use App\Support\Settings;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class TaskLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->user = User::factory()->create();
        $this->agent = Agent::factory()->create();
    }

    public function test_task_moves_pending_queued_running_completed_with_timestamps(): void
    {
        Queue::fake();
        Event::fake([TaskUpdated::class, NotificationCreated::class]);

        $conversation = Conversation::factory()->for($this->user)->create();
        $message = Message::factory()->for($conversation)->for($this->user)->fromUser()
            ->create(['content' => 'Procure clientes inadimplentes no ERP']);

        $task = app(TaskService::class)->createFromMessage($message, $this->agent, 'Clientes inadimplentes');

        $this->assertSame(TaskStatus::QUEUED, $task->status->slug);
        $this->assertNotNull($task->queued_at);
        $this->assertNull($task->started_at);
        $this->assertNull($task->completed_at);
        $this->assertSame($message->id, $task->message_id);
        $this->assertSame($this->agent->id, $task->agent_id);
        $this->assertSame('Clientes inadimplentes', $task->title);

        Queue::assertPushedOn('tasks', RunTask::class);

        $this->stubRunner(fn (): AgentExecution => $this->completedExecution());

        (new RunTask($task))->handle(app(TaskService::class));

        $task->refresh();

        $this->assertSame(TaskStatus::COMPLETED, $task->status->slug);
        $this->assertNotNull($task->started_at);
        $this->assertNotNull($task->completed_at);
        $this->assertSame(100, $task->progress);
        $this->assertSame('text', $task->resultType->slug);
        $this->assertSame('Tudo pronto.', $task->result['text']);

        Event::assertDispatched(TaskUpdated::class);
    }

    public function test_steps_and_logs_are_recorded_in_order(): void
    {
        Event::fake([TaskUpdated::class, NotificationCreated::class]);

        $task = Task::factory()->queued()->for($this->user)->for($this->agent)->create(['progress' => 0]);

        $this->stubRunner(function (): AgentExecution {
            $context = app(TaskContext::class);

            $first = $context->startStep('Buscar clientes');
            $context->log('Consulta iniciada', LogLevel::INFO, $first);
            $context->setProgress(40);
            $context->finishStep($first, ['rows' => 12]);

            $second = $context->startStep('Gerar relatório');
            $context->log('Atenção: dados parciais', LogLevel::WARNING, $second);
            $context->finishStep($second, ['pages' => 1]);

            return $this->completedExecution();
        });

        (new RunTask($task))->handle(app(TaskService::class));

        $this->assertSame([1, 2], $task->fresh()->steps()->orderBy('position')->pluck('position')->all());
        $this->assertSame(['Buscar clientes', 'Gerar relatório'], $task->steps()->orderBy('position')->pluck('name')->all());
        $this->assertSame(
            [TaskStatus::COMPLETED, TaskStatus::COMPLETED],
            $task->steps()->with('status')->orderBy('position')->get()->map(fn ($step): string => $step->status->slug)->all(),
        );
        $this->assertSame(['rows' => 12], $task->steps()->first()->output);

        $logs = $task->logs()->with('level')->orderBy('id')->get();

        $this->assertSame(['Consulta iniciada', 'Atenção: dados parciais'], $logs->pluck('message')->all());
        $this->assertSame([LogLevel::INFO, LogLevel::WARNING], $logs->pluck('level.slug')->all());
        $this->assertSame($task->steps()->first()->id, $logs[0]->task_step_id);
    }

    public function test_progress_cannot_decrease(): void
    {
        Event::fake([TaskUpdated::class, NotificationCreated::class]);

        $task = Task::factory()->running()->for($this->user)->create(['progress' => 40]);

        $context = app(TaskContext::class, ['task' => $task]);

        $context->setProgress(70);
        $context->setProgress(30);
        $context->setProgress(200);

        $this->assertSame(100, $task->fresh()->progress);

        Event::assertDispatched(TaskUpdated::class, 1);
    }

    public function test_timeout_marks_task_failed(): void
    {
        Event::fake([TaskUpdated::class, NotificationCreated::class]);

        $task = Task::factory()->queued()->for($this->user)->for($this->agent)->create();

        $this->stubRunner(fn (): never => throw new RuntimeException('A execução do agente timed out.'));

        (new RunTask($task))->handle(app(TaskService::class));

        $task->refresh();

        $this->assertSame(TaskStatus::FAILED, $task->status->slug);
        $this->assertSame('timeout', $task->error);
        $this->assertNotNull($task->completed_at);
    }

    public function test_exception_marks_failed_with_error(): void
    {
        Event::fake([TaskUpdated::class, NotificationCreated::class]);

        $task = Task::factory()->queued()->for($this->user)->for($this->agent)->create();

        $this->stubRunner(fn (): never => throw new RuntimeException('O ERP recusou a conexão.'));

        (new RunTask($task))->handle(app(TaskService::class));

        $task->refresh();

        $this->assertSame(TaskStatus::FAILED, $task->status->slug);
        $this->assertSame('O ERP recusou a conexão.', $task->error);
        $this->assertNotNull($task->completed_at);
        $this->assertNull($task->result);
    }

    public function test_create_from_voice_queues_on_tasks_queue(): void
    {
        Queue::fake();
        Event::fake([TaskUpdated::class]);

        $session = VoiceSession::factory()->for($this->user)->create();
        $model = AiModel::factory()->create();
        $visualization = Visualization::factory()->for($this->user)->create();

        $task = app(TaskService::class)->createFromVoice(
            $this->user,
            $session,
            $this->agent,
            'Busque no blog a aula de inglês sobre phrasal verbs',
            $visualization,
            $model,
        );

        $this->assertSame(TaskStatus::QUEUED, $task->status->slug);
        $this->assertNotNull($task->queued_at);
        $this->assertSame($this->user->id, $task->user_id);
        $this->assertSame($this->agent->id, $task->agent_id);
        $this->assertSame($session->id, $task->voice_session_id);
        $this->assertSame($visualization->id, $task->visualization_id);
        $this->assertSame($model->id, $task->ai_model_id);
        $this->assertNull($task->message_id);
        $this->assertSame(1, Task::query()->where('voice_session_id', $session->id)->count());

        Queue::assertPushedOn(RunTask::QUEUE, RunTask::class, fn (RunTask $job): bool => $job->task->is($task));
        Event::assertDispatched(TaskUpdated::class);

        $prompt = app(TaskService::class)->resumePrompt($task, 0);
        $this->assertStringStartsWith('Busque no blog a aula de inglês sobre phrasal verbs', $prompt);
        $this->assertStringContainsString('visualization.update_section', $prompt);
    }

    public function test_create_from_voice_uses_given_session_conversation_even_with_newer_session(): void
    {
        Queue::fake();

        $given = VoiceSession::factory()->for($this->user)->create(['created_at' => now()->subMinutes(5)]);
        $newer = VoiceSession::factory()->for($this->user)->create();
        $closedVisualization = Visualization::factory()->for($this->user)->closed()->create();

        $task = app(TaskService::class)->createFromVoice(
            $this->user,
            $given,
            $this->agent,
            str_repeat('pedido longo ', 20),
            $closedVisualization,
            AiModel::factory()->create(),
        );

        $this->assertSame($given->conversation_id, $task->conversation_id);
        $this->assertNotSame($newer->conversation_id, $task->conversation_id);
        $this->assertSame($given->id, $task->voice_session_id);
        $this->assertNull($task->visualization_id);
        $this->assertLessThanOrEqual(80, mb_strlen($task->title));
    }

    public function test_text_task_prompt_has_no_voice_suffix(): void
    {
        $task = Task::factory()->for($this->user)->create(['request' => 'Pedido comum']);

        $this->assertSame('Pedido comum', app(TaskService::class)->resumePrompt($task, 0));
    }

    /**
     * Replaces the real agent execution with a scripted one, exactly like the
     * `task.*` tools would behave inside a real run: the closure receives the
     * bound TaskContext through the container.
     */
    private function stubRunner(Closure $handler): void
    {
        StubTaskRunner::$handler = $handler;

        $stub = new StubTaskRunner(
            app(AgentRuntimeFactory::class),
            app(ProviderRegistry::class),
            app(UsageRecorder::class),
            app(Settings::class),
            app(StructuredResultExtractor::class),
        );

        $this->app->instance(AgentRunner::class, $stub);
    }

    private function completedExecution(): AgentExecution
    {
        return AgentExecution::factory()->for($this->user)->for($this->agent)->create([
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::COMPLETED),
            'meta' => ['output' => 'Tudo pronto.'],
        ]);
    }
}

class StubTaskRunner extends AgentRunner
{
    public static ?Closure $handler = null;

    public function run(Agent $agent, User $user, string $prompt, RunOptions $options): AgentExecution
    {
        return (self::$handler)($agent, $user, $prompt, $options);
    }
}
