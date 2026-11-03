<?php

namespace Tests\Feature;

use App\Ai\Agents\RuntimeAgent;
use App\Jobs\Middleware\WithLogContext;
use App\Jobs\RunTask;
use App\Models\Agent;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Task;
use App\Models\User;
use App\Services\AgentRunner;
use App\Services\RunOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Ai;
use Monolog\Formatter\JsonFormatter;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

/**
 * Structured logs (observability): job log lines carry `user_id`,
 * `conversation_id`, `task_id` and `agent_execution_id`, and the context
 * never outlives the job.
 */
class LogContextTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    /**
     * @var list<array<string, mixed>>
     */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        Log::listen(function (MessageLogged $event): void {
            $this->logged[] = $event->context;
        });
    }

    public function test_task_job_logs_carry_identifiers_and_are_cleared_afterwards(): void
    {
        $task = Task::factory()->for(User::factory())->for(Agent::factory())->create();

        [$middleware] = (new RunTask($task))->middleware();

        $this->assertInstanceOf(WithLogContext::class, $middleware);

        $middleware->handle(new \stdClass, function (): void {
            Log::info('Executando tarefa.');
        });

        Log::info('Depois do job.');

        $this->assertSame($task->user_id, $this->logged[0]['user_id']);
        $this->assertSame($task->id, $this->logged[0]['task_id']);
        $this->assertSame($task->conversation_id, $this->logged[0]['conversation_id'] ?? null);

        $this->assertArrayNotHasKey('task_id', $this->logged[1]);
        $this->assertArrayNotHasKey('user_id', $this->logged[1]);
    }

    public function test_agent_runs_add_the_agent_execution_id(): void
    {
        Event::fake();

        AiModel::factory()->defaultText()->for(AiProvider::factory(), 'provider')->create();

        Ai::fakeAgent(RuntimeAgent::class, function (): string {
            Log::info('Dentro da execução.');

            return 'ok';
        });

        $execution = app(AgentRunner::class)->run(Agent::factory()->create(), User::factory()->create(), 'Oi', new RunOptions);

        Log::info('Fora da execução.');

        $inside = collect($this->logged)->first(fn (array $context): bool => isset($context['agent_execution_id']));

        $this->assertNotNull($inside);
        $this->assertSame($execution->id, $inside['agent_execution_id']);
        $this->assertArrayNotHasKey('agent_execution_id', end($this->logged));
    }

    public function test_json_channel_uses_the_json_formatter(): void
    {
        $handlers = Log::channel('json')->getLogger()->getHandlers();

        $this->assertInstanceOf(JsonFormatter::class, $handlers[0]->getFormatter());
    }
}
