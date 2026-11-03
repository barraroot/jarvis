<?php

namespace Tests\Feature;

use App\Ai\Agents\RuntimeAgent;
use App\Jobs\RunTask;
use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\ExecutionStatus;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Tool;
use App\Models\ToolExecution;
use App\Models\User;
use App\Services\DelegationService;
use App\Services\TaskService;
use App\Support\Settings;
use App\Tools\Contracts\JarvisTool;
use App\Tools\Internal\CurrentTimeTool;
use App\Tools\Internal\StartTaskStepTool;
use App\Tools\ToolContext;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Ai;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\StructuredAnonymousAgent;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

/**
 * US-7.3, US-8.2, US-10.3: execution limits verified end to end through
 * `RunTask` with a faked SDK — three-level delegation, the 21st tool call,
 * tool timeout, delegation cycles and mid-run cancellation — always keeping
 * `agent_executions`/`tool_executions` consistent and inside the owner.
 */
class ExecutionLimitsIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $owner;

    private User $bystander;

    private Task $bystanderTask;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        Event::fake();
        Queue::fake();

        AiModel::factory()->defaultText()->for(AiProvider::factory(), 'provider')->create();

        Ai::fakeAgent(StructuredAnonymousAgent::class, fn (): array => ['result' => ['type' => 'text', 'text' => 'ok']]);

        $this->owner = User::factory()->create();
        $this->bystander = User::factory()->create();
        $this->bystanderTask = Task::factory()->queued()->for($this->bystander)->for(Agent::factory())->create();

        SlowTool::$finished = false;
    }

    public function test_long_task_with_three_level_delegation_completes_consistently(): void
    {
        $orchestrator = Agent::factory()->create(['slug' => 'orquestrador']);
        $researcher = Agent::factory()->create(['slug' => 'pesquisa']);
        $analyst = Agent::factory()->create(['slug' => 'analise']);

        app(DelegationService::class)->syncTools();

        $orchestrator->tools()->attach($this->tool('delegate.pesquisa'));
        $researcher->tools()->attach($this->tool('delegate.analise'));
        $analyst->tools()->attach($this->timeTool());

        $this->fakeByPrompt([
            'nível 2' => [new ToolCall('call-mid', 'delegate.analise', ['task' => 'nível 3']), 'Análise consolidada.'],
            'nível 3' => [new ToolCall('call-leaf', 'time.current', []), 'Folha concluída.'],
            '*' => [new ToolCall('call-root', 'delegate.pesquisa', ['task' => 'nível 2']), 'Relatório final.'],
        ]);

        $task = $this->runTask($orchestrator);

        $this->assertSame(TaskStatus::COMPLETED, $task->statusSlug());

        $executions = AgentExecution::query()->where('task_id', $task->id)->orderBy('depth')->get();

        $this->assertSame([0, 1, 2], $executions->pluck('depth')->all());
        $this->assertSame(
            [$orchestrator->id, $researcher->id, $analyst->id],
            $executions->pluck('agent_id')->all(),
        );
        $this->assertNull($executions[0]->parent_agent_execution_id);
        $this->assertSame($executions[0]->id, $executions[1]->parent_agent_execution_id);
        $this->assertSame($executions[1]->id, $executions[2]->parent_agent_execution_id);
        $this->assertSame([1, 1, 1], $executions->pluck('tool_call_count')->all());
        $this->assertTrue($executions->every(fn (AgentExecution $execution): bool => $execution->execution_status_id === ExecutionStatus::idFor(ExecutionStatus::COMPLETED)));

        $leafCall = ToolExecution::query()->sole();
        $this->assertSame($executions[2]->id, $leafCall->agent_execution_id);
        $this->assertSame(ExecutionStatus::idFor(ExecutionStatus::COMPLETED), $leafCall->execution_status_id);

        $this->assertConsistentAndIsolated();
    }

    public function test_21st_tool_call_is_blocked(): void
    {
        $agent = Agent::factory()->create(['max_tool_calls' => 0]);
        $agent->tools()->attach($this->timeTool());

        $this->assertSame(20, app(Settings::class)->maxToolCalls());

        // The model never stops asking for tools.
        Ai::fakeAgent(RuntimeAgent::class, fn (): ToolCall => new ToolCall('call-'.uniqid(), 'time.current', []));

        $task = $this->runTask($agent);

        $this->assertSame(TaskStatus::FAILED, $task->statusSlug());

        $execution = AgentExecution::query()->where('task_id', $task->id)->sole();

        $this->assertSame(ExecutionStatus::idFor(ExecutionStatus::FAILED), $execution->execution_status_id);
        $this->assertSame(21, $execution->tool_call_count);
        $this->assertStringContainsString('excedeu o limite de 20 chamadas', $execution->error);

        // Twenty calls ran; the 21st was blocked before executing.
        $this->assertSame(20, ToolExecution::query()->where('agent_execution_id', $execution->id)->count());

        $this->assertConsistentAndIsolated();
    }

    public function test_tool_timeout_is_recorded_and_the_task_continues(): void
    {
        $agent = Agent::factory()->create();
        $agent->tools()->attach(Tool::factory()->internal()->create([
            'identifier' => 'ops.slow',
            'handler_class' => SlowTool::class,
            'input_schema' => ['type' => 'object', 'properties' => []],
            'output_schema' => ['type' => 'object', 'properties' => []],
            'timeout_seconds' => 1,
            'is_write' => false,
            'is_sensitive' => false,
        ]));

        Ai::fakeAgent(RuntimeAgent::class, [
            new ToolCall('call-slow', 'ops.slow', []),
            'A ferramenta expirou; segue o que foi possível.',
        ]);

        $task = $this->runTask($agent);

        $this->assertSame(TaskStatus::COMPLETED, $task->statusSlug());
        $this->assertFalse(SlowTool::$finished, 'The slow handler must be interrupted.');

        $slow = ToolExecution::query()->sole();

        $this->assertSame(ExecutionStatus::idFor(ExecutionStatus::TIMED_OUT), $slow->execution_status_id);
        $this->assertNotNull($slow->finished_at);
        $this->assertNotNull($slow->duration_ms);

        $this->assertConsistentAndIsolated();
    }

    public function test_delegation_cycle_is_blocked(): void
    {
        $first = Agent::factory()->create(['slug' => 'primeiro']);
        $second = Agent::factory()->create(['slug' => 'segundo']);

        app(DelegationService::class)->syncTools();

        $first->tools()->attach($this->tool('delegate.segundo'));
        $second->tools()->attach($this->tool('delegate.primeiro'));

        $this->fakeByPrompt([
            'volta' => [new ToolCall('call-back', 'delegate.primeiro', ['task' => 'ciclo']), 'Não foi possível voltar.'],
            'ciclo' => ['Nunca deveria rodar.'],
            '*' => [new ToolCall('call-go', 'delegate.segundo', ['task' => 'volta']), 'Concluído sem ciclo.'],
        ]);

        $task = $this->runTask($first);

        $this->assertSame(TaskStatus::COMPLETED, $task->statusSlug());

        $executions = AgentExecution::query()->where('task_id', $task->id)->orderBy('depth')->get();

        // The cycle never produced a third execution.
        $this->assertSame([$first->id, $second->id], $executions->pluck('agent_id')->all());
        $this->assertSame([1, 1], $executions->pluck('tool_call_count')->all());

        $this->assertConsistentAndIsolated();
    }

    public function test_cancellation_mid_run_stops_the_next_step(): void
    {
        $agent = Agent::factory()->create();
        $agent->tools()->attach([
            Tool::factory()->internal()->create([
                'identifier' => 'ops.cancel_self',
                'handler_class' => CancelOwnTaskTool::class,
                'input_schema' => ['type' => 'object', 'properties' => []],
                'output_schema' => ['type' => 'object', 'properties' => []],
                'is_write' => false,
                'is_sensitive' => false,
            ])->id,
            Tool::factory()->internal()->create([
                'identifier' => 'task.start_step',
                'handler_class' => StartTaskStepTool::class,
                'input_schema' => app(StartTaskStepTool::class)->inputSchema(),
                'output_schema' => app(StartTaskStepTool::class)->outputSchema(),
                'is_write' => false,
                'is_sensitive' => false,
            ])->id,
        ]);

        Ai::fakeAgent(RuntimeAgent::class, [
            new ToolCall('call-cancel', 'ops.cancel_self', []),
            new ToolCall('call-step', 'task.start_step', ['name' => 'Etapa depois do cancelamento']),
            'Encerrando.',
        ]);

        $task = $this->runTask($agent);

        $this->assertSame(TaskStatus::CANCELLED, $task->statusSlug());
        $this->assertSame(0, $task->steps()->count(), 'No step may start after cancellation.');

        $blocked = ToolExecution::query()
            ->whereHas('tool', fn ($query) => $query->where('identifier', 'task.start_step'))
            ->sole();

        $this->assertSame(ExecutionStatus::idFor(ExecutionStatus::FAILED), $blocked->execution_status_id);

        $this->assertConsistentAndIsolated();
    }

    public function test_task_jobs_run_on_the_dedicated_tasks_queue(): void
    {
        $task = Task::factory()->pending()->for($this->owner)->for(Agent::factory())->create();

        app(TaskService::class)->queue($task);
        RunTask::dispatch($task);

        Queue::assertPushedOn('tasks', RunTask::class);
        Queue::assertPushed(RunTask::class, fn (RunTask $job): bool => $job->queue === RunTask::QUEUE);
        Queue::assertNotPushed(RunTask::class, fn (RunTask $job): bool => $job->queue !== RunTask::QUEUE);
    }

    private function runTask(Agent $agent): Task
    {
        $task = Task::factory()->queued()->for($this->owner)->for($agent)->create();

        (new RunTask($task))->handle(app(TaskService::class));

        return $task->fresh();
    }

    /**
     * Fake the runtime agent by prompt: each prompt walks its own script; the
     * `*` script answers any other prompt (the root task prompt).
     *
     * @param  array<string, list<mixed>>  $scripts
     */
    private function fakeByPrompt(array $scripts): void
    {
        $positions = [];

        Ai::fakeAgent(RuntimeAgent::class, function (string $prompt) use ($scripts, &$positions): mixed {
            $key = array_key_exists($prompt, $scripts) ? $prompt : '*';
            $position = $positions[$key] ?? 0;
            $positions[$key] = $position + 1;

            return $scripts[$key][min($position, count($scripts[$key]) - 1)];
        });
    }

    private function tool(string $identifier): Tool
    {
        return Tool::query()->where('identifier', $identifier)->firstOrFail();
    }

    private function timeTool(): Tool
    {
        return Tool::factory()->internal()->create([
            'identifier' => 'time.current',
            'handler_class' => CurrentTimeTool::class,
            'is_write' => false,
            'is_sensitive' => false,
        ]);
    }

    /**
     * Nothing stays running, every tool execution belongs to an agent
     * execution of the same owner, and nothing touched or reached the
     * bystander (data or realtime channel).
     */
    private function assertConsistentAndIsolated(): void
    {
        $this->assertFalse(
            AgentExecution::query()->where('execution_status_id', ExecutionStatus::idFor(ExecutionStatus::RUNNING))->exists(),
            'No agent execution may be left running.',
        );
        $this->assertFalse(
            ToolExecution::query()->where('execution_status_id', ExecutionStatus::idFor(ExecutionStatus::RUNNING))->exists(),
            'No tool execution may be left running.',
        );

        $this->assertSame([$this->owner->id], AgentExecution::query()->distinct()->pluck('user_id')->all());

        foreach (ToolExecution::query()->with('agentExecution')->get() as $toolExecution) {
            $this->assertSame($this->owner->id, $toolExecution->user_id);
            $this->assertNotNull($toolExecution->agentExecution);
            $this->assertSame($toolExecution->user_id, $toolExecution->agentExecution->user_id);
        }

        foreach (AgentExecution::query()->whereNotNull('parent_agent_execution_id')->with('parent')->get() as $child) {
            $this->assertSame($child->parent->depth + 1, $child->depth);
            $this->assertSame($child->parent->task_id, $child->task_id);
        }

        $this->assertSame(TaskStatus::QUEUED, $this->bystanderTask->fresh()->statusSlug());
        $this->assertSame(0, $this->bystanderTask->steps()->count());

        $channels = [];

        foreach (Event::dispatchedEvents() as $dispatches) {
            foreach ($dispatches as $arguments) {
                if (($arguments[0] ?? null) instanceof ShouldBroadcast) {
                    foreach (Arr::wrap($arguments[0]->broadcastOn()) as $channel) {
                        $channels[] = $channel instanceof Channel ? $channel->name : (string) $channel;
                    }
                }
            }
        }

        $this->assertNotEmpty($channels);
        $this->assertSame(['private-user.'.$this->owner->id], array_values(array_unique($channels)));
    }
}

class SlowTool implements JarvisTool
{
    public static bool $finished = false;

    public function identifier(): string
    {
        return 'ops.slow';
    }

    public function name(): string
    {
        return 'Operação lenta';
    }

    public function description(): string
    {
        return 'Demora mais que o timeout.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function handle(array $input, ToolContext $context): array
    {
        sleep(3);

        self::$finished = true;

        return [];
    }
}

class CancelOwnTaskTool implements JarvisTool
{
    public function identifier(): string
    {
        return 'ops.cancel_self';
    }

    public function name(): string
    {
        return 'Cancelamento simulado';
    }

    public function description(): string
    {
        return 'Simula o operador cancelando a tarefa durante a execução.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function handle(array $input, ToolContext $context): array
    {
        app(TaskService::class)->cancel($context->task->fresh(), $context->user);

        return [];
    }
}
