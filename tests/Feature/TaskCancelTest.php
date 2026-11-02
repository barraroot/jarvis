<?php

namespace Tests\Feature;

use App\Events\Task\TaskUpdated;
use App\Exceptions\TaskNotCancellableException;
use App\Jobs\RunTask;
use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\Approval;
use App\Models\ApprovalStatus;
use App\Models\ExecutionStatus;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

/**
 * Cooperative cancellation (US-10.3): cancelling a running task aborts the job
 * at the next step boundary and denies the task's pending approvals.
 */
class TaskCancelTest extends TestCase
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

    public function test_cancel_running_task_stops_before_next_step(): void
    {
        Queue::fake();
        Event::fake([TaskUpdated::class]);

        $task = Task::factory()->queued()->for($this->user)->for($this->agent)->create();

        $this->stubRunner(function () use ($task): AgentExecution {
            $context = app(TaskContext::class);

            $step = $context->startStep('Buscar clientes');
            $context->finishStep($step, ['rows' => 12]);

            // The owner cancels mid-run: the next step must never start.
            app(TaskService::class)->cancel($task->fresh(), $this->user);

            $context->startStep('Enviar lembretes');

            return $this->completedExecution();
        });

        (new RunTask($task))->handle(app(TaskService::class));

        $task->refresh();

        $this->assertSame(TaskStatus::CANCELLED, $task->status->slug);
        $this->assertNotNull($task->completed_at);
        $this->assertNull($task->result);
        $this->assertNull($task->error);
        $this->assertSame(['Buscar clientes'], $task->steps()->orderBy('position')->pluck('name')->all());
        $this->assertSame(0, $task->steps()->where('name', 'Enviar lembretes')->count());

        Event::assertDispatched(TaskUpdated::class);
    }

    public function test_cancel_denies_pending_approvals(): void
    {
        Event::fake([TaskUpdated::class]);

        $task = Task::factory()->waitingApproval()->for($this->user)->for($this->agent)->create();

        $pending = Approval::factory()->for($this->user)->create(['task_id' => $task->id]);
        $decided = Approval::factory()->for($this->user)->approved()->create(['task_id' => $task->id]);

        app(TaskService::class)->cancel($task, $this->user);

        $this->assertSame(TaskStatus::CANCELLED, $task->fresh()->status->slug);

        $pending->refresh();
        $this->assertSame(ApprovalStatus::DENIED, $pending->status->slug);
        $this->assertSame('tarefa cancelada', $pending->decision_reason);
        $this->assertSame($this->user->id, $pending->decided_by_user_id);
        $this->assertNotNull($pending->decided_at);

        // Already decided approvals stay untouched.
        $this->assertSame(ApprovalStatus::APPROVED, $decided->fresh()->status->slug);

        $this->assertTrue(Cache::get(TaskService::cancellationKey($task->id)));
    }

    public function test_cancel_rejects_terminal_tasks(): void
    {
        $task = Task::factory()->completed()->for($this->user)->for($this->agent)->create();

        $this->assertThrows(
            fn (): mixed => app(TaskService::class)->cancel($task, $this->user),
            TaskNotCancellableException::class,
        );

        $this->assertSame(TaskStatus::COMPLETED, $task->fresh()->status->slug);
    }

    /**
     * @param  Closure(Agent, User, string, RunOptions): AgentExecution  $handler
     */
    private function stubRunner(Closure $handler): void
    {
        TaskCancelStubRunner::$handler = $handler;

        $this->app->instance(AgentRunner::class, new TaskCancelStubRunner(
            app(AgentRuntimeFactory::class),
            app(ProviderRegistry::class),
            app(UsageRecorder::class),
            app(Settings::class),
            app(StructuredResultExtractor::class),
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

class TaskCancelStubRunner extends AgentRunner
{
    public static ?Closure $handler = null;

    public function run(Agent $agent, User $user, string $prompt, RunOptions $options): AgentExecution
    {
        return (self::$handler)($agent, $user, $prompt, $options);
    }
}
