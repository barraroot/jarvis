<?php

namespace Tests\Feature;

use App\Events\Assistant\AssistantStateChanged;
use App\Events\NotificationCreated;
use App\Events\Task\TaskUpdated;
use App\Events\Tool\ToolCompleted;
use App\Events\Tool\ToolStarted;
use App\Jobs\RunTask;
use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\Approval;
use App\Models\ApprovalStatus;
use App\Models\ExecutionStatus;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Tool;
use App\Models\User;
use App\Services\AgentRunner;
use App\Services\AgentRuntimeFactory;
use App\Services\ApprovalService;
use App\Services\ProviderRegistry;
use App\Services\RunOptions;
use App\Services\TaskContext;
use App\Services\TaskService;
use App\Services\UsageRecorder;
use App\Support\Settings;
use App\Tools\Internal\CurrentTimeTool;
use App\Tools\ToolContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

/**
 * Workflow 5, steps 3–4 (US-8.3, US-10.3): a gated tool parks the task in
 * `waiting_approval` and ends the job; the decision re-dispatches `RunTask`
 * resuming after the concluded steps.
 */
class TaskApprovalIntegrationTest extends TestCase
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

    public function test_approval_required_step_parks_task_in_waiting_approval(): void
    {
        Queue::fake();
        Event::fake([TaskUpdated::class, AssistantStateChanged::class, NotificationCreated::class]);

        $task = Task::factory()->queued()->for($this->user)->for($this->agent)->create();

        $tool = Tool::factory()->write()->create(['identifier' => 'erp.send_reminder']);

        $this->stubRunner(function () use ($tool): AgentExecution {
            $context = app(TaskContext::class);

            $step = $context->startStep('Enviar lembretes');

            // The gated tool call opens the approval instead of running.
            app(ApprovalService::class)->request(
                $tool,
                ['customer_ids' => [42, 77]],
                new ToolContext($this->user, task: app(TaskContext::class)->task()->fresh()),
            );

            $context->log('Aguardando decisão do operador');

            return $this->completedExecution();
        });

        (new RunTask($task))->handle(app(TaskService::class));

        $task->refresh();

        $this->assertSame(TaskStatus::WAITING_APPROVAL, $task->status->slug);
        $this->assertNull($task->completed_at);
        $this->assertNull($task->result);

        $approval = Approval::query()->where('task_id', $task->id)->firstOrFail();
        $this->assertTrue($approval->isPending());
        $this->assertSame(['customer_ids' => [42, 77]], $approval->input);

        // The job ended and freed the worker; nothing re-dispatches until the decision.
        Queue::assertNotPushed(RunTask::class);

        $this->assertSame(1, $task->steps()->count());
        $this->assertSame(TaskStatus::RUNNING, $task->steps()->first()->status->slug);
    }

    public function test_approve_resumes_from_next_step(): void
    {
        Queue::fake();
        Event::fake([TaskUpdated::class, NotificationCreated::class, ToolStarted::class, ToolCompleted::class]);

        $task = Task::factory()->waitingApproval()->for($this->user)->for($this->agent)->create();

        $task->steps()->create([
            'task_status_id' => TaskStatus::idFor(TaskStatus::COMPLETED),
            'position' => 1,
            'name' => 'Buscar clientes',
            'output' => ['rows' => 12],
            'started_at' => now()->subMinutes(2),
            'finished_at' => now()->subMinute(),
        ]);
        $task->steps()->create([
            'task_status_id' => TaskStatus::idFor(TaskStatus::COMPLETED),
            'position' => 2,
            'name' => 'Classificar inadimplência',
            'output' => ['groups' => 3],
            'started_at' => now()->subMinute(),
            'finished_at' => now()->subSeconds(30),
        ]);

        $tool = Tool::factory()->internal()->create([
            'identifier' => 'time.current',
            'handler_class' => CurrentTimeTool::class,
            'is_write' => false,
            'is_sensitive' => false,
        ]);
        $approval = Approval::factory()->for($this->user)->create([
            'tool_id' => $tool->id,
            'task_id' => $task->id,
            'input' => [],
        ]);

        app(ApprovalService::class)->approve($approval, $this->user);

        $this->assertSame(ApprovalStatus::APPROVED, $approval->fresh()->status->slug);
        $this->assertSame(TaskStatus::RUNNING, $task->fresh()->status->slug);

        Queue::assertPushedOn('tasks', RunTask::class, fn (RunTask $job): bool => $job->resumeFromStep === 2 && $job->task->is($task));

        // The resumed run never repeats concluded steps: re-registering them is a no-op.
        $this->stubRunner(function (): AgentExecution {
            $context = app(TaskContext::class);

            $context->startStep('Buscar clientes');
            $context->startStep('Classificar inadimplência');
            $context->startStep('Enviar lembretes');
            $context->finishStep($context->startStep('Enviar lembretes'), ['sent' => 12]);

            return $this->completedExecution();
        });

        (new RunTask($task->fresh(), 2))->handle(app(TaskService::class));

        $task->refresh();

        $this->assertSame([1, 2, 3], $task->steps()->orderBy('position')->pluck('position')->all());
        $this->assertSame(
            ['Buscar clientes', 'Classificar inadimplência', 'Enviar lembretes'],
            $task->steps()->orderBy('position')->pluck('name')->all(),
        );
        $this->assertSame(TaskStatus::COMPLETED, $task->status->slug);
    }

    /**
     * @param  Closure(Agent, User, string, RunOptions): AgentExecution  $handler
     */
    private function stubRunner(Closure $handler): void
    {
        TaskApprovalStubRunner::$handler = $handler;

        $this->app->instance(AgentRunner::class, new TaskApprovalStubRunner(
            app(AgentRuntimeFactory::class),
            app(ProviderRegistry::class),
            app(UsageRecorder::class),
            app(Settings::class),
        ));
    }

    private function completedExecution(): AgentExecution
    {
        return AgentExecution::factory()->for($this->user)->for($this->agent)->create([
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::COMPLETED),
            'meta' => ['output' => 'Concluído.'],
        ]);
    }
}

class TaskApprovalStubRunner extends AgentRunner
{
    public static ?Closure $handler = null;

    public function run(Agent $agent, User $user, string $prompt, RunOptions $options): AgentExecution
    {
        return (self::$handler)($agent, $user, $prompt, $options);
    }
}
