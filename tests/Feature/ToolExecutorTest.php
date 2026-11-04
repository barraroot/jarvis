<?php

namespace Tests\Feature;

use App\Events\Tool\ToolCompleted;
use App\Events\Tool\ToolFailed;
use App\Events\Tool\ToolStarted;
use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\ExecutionStatus;
use App\Models\Tool;
use App\Models\User;
use App\Tools\Contracts\JarvisTool;
use App\Tools\Exceptions\ToolInputInvalidException;
use App\Tools\Exceptions\ToolNotAuthorizedException;
use App\Tools\ToolContext;
use App\Tools\ToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class ToolExecutorTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->user = User::factory()->create();
    }

    public function test_invalid_input_fails_without_running_handler(): void
    {
        EchoInternalTool::$called = false;

        $tool = $this->echoTool();

        $this->assertThrows(
            fn (): mixed => $this->executor()->execute($tool, ['unexpected' => 1], $this->context()),
            ToolInputInvalidException::class,
        );

        $this->assertFalse(EchoInternalTool::$called, 'The handler must not run on invalid input.');

        $this->assertDatabaseHas('tool_executions', [
            'tool_id' => $tool->id,
            'user_id' => $this->user->id,
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::FAILED),
        ]);

        $execution = $tool->executions()->firstOrFail();
        $this->assertStringContainsString('message is required', $execution->error);
        $this->assertNull($execution->output);
    }

    public function test_timeout_marks_execution_timed_out(): void
    {
        Event::fake([ToolStarted::class, ToolFailed::class]);

        $tool = Tool::factory()->create([
            'identifier' => 'test.slow',
            'handler_class' => SlowInternalTool::class,
            'timeout_seconds' => 1,
            'input_schema' => ['type' => 'object', 'properties' => []],
            'output_schema' => ['type' => 'object', 'properties' => []],
            'is_write' => false,
            'is_sensitive' => false,
        ]);

        $result = $this->executor()->execute($tool, [], $this->context());

        $this->assertSame(ExecutionStatus::TIMED_OUT, $result->status);
        $this->assertNotNull($result->execution);
        $this->assertSame(ExecutionStatus::TIMED_OUT, $result->execution->status->slug);
        $this->assertNotNull($result->execution->finished_at);

        Event::assertDispatched(ToolFailed::class, fn (ToolFailed $event): bool => array_intersect_key($event->broadcastWith(), array_flip(['tool', 'execution_id', 'status'])) === [
            'tool' => 'test.slow',
            'execution_id' => $result->execution->id,
            'status' => 'timed_out',
        ]);
    }

    public function test_output_schema_violation_fails(): void
    {
        Event::fake([ToolStarted::class, ToolFailed::class]);

        $tool = Tool::factory()->create([
            'identifier' => 'test.bad-output',
            'handler_class' => BadOutputInternalTool::class,
            'input_schema' => ['type' => 'object', 'properties' => []],
            'output_schema' => [
                'type' => 'object',
                'properties' => ['echoed' => ['type' => 'string']],
                'required' => ['echoed'],
            ],
            'is_write' => false,
            'is_sensitive' => false,
        ]);

        $result = $this->executor()->execute($tool, [], $this->context());

        $this->assertSame(ExecutionStatus::FAILED, $result->status);
        $this->assertStringContainsString('echoed is required', $result->error);

        $this->assertDatabaseHas('tool_executions', [
            'tool_id' => $tool->id,
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::FAILED),
        ]);
    }

    public function test_success_records_duration_and_emits_events(): void
    {
        Event::fake([ToolStarted::class, ToolCompleted::class]);

        $tool = $this->echoTool();

        $result = $this->executor()->execute($tool, ['message' => 'olá'], $this->context());

        $this->assertTrue($result->isCompleted());
        $this->assertSame(['echoed' => 'olá'], $result->output);
        $this->assertNotNull($result->execution);
        $this->assertSame(ExecutionStatus::COMPLETED, $result->execution->status->slug);
        $this->assertNotNull($result->execution->duration_ms);
        $this->assertNotNull($result->execution->started_at);
        $this->assertNotNull($result->execution->finished_at);

        $this->assertDatabaseHas('tool_executions', [
            'tool_id' => $tool->id,
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::COMPLETED),
        ]);

        Event::assertDispatched(ToolStarted::class, fn (ToolStarted $event): bool => array_intersect_key($event->broadcastWith(), array_flip(['tool', 'execution_id', 'status'])) === [
            'tool' => 'test.echo',
            'execution_id' => $result->execution->id,
            'status' => 'running',
        ]);

        Event::assertDispatched(ToolCompleted::class, fn (ToolCompleted $event): bool => array_intersect_key($event->broadcastWith(), array_flip(['tool', 'execution_id', 'status'])) === [
            'tool' => 'test.echo',
            'execution_id' => $result->execution->id,
            'status' => 'completed',
        ]);
    }

    public function test_unauthorized_tool_is_rejected(): void
    {
        Event::fake([ToolStarted::class, ToolCompleted::class]);

        $inactive = $this->echoTool(['is_active' => false, 'identifier' => 'test.echo-inactive']);

        $this->assertThrows(
            fn (): mixed => $this->executor()->execute($inactive, ['message' => 'x'], $this->context()),
            ToolNotAuthorizedException::class,
        );

        $agent = Agent::factory()->create();
        $execution = AgentExecution::factory()->for($this->user)->for($agent)->running()->create();
        $tool = $this->echoTool();

        $this->assertThrows(
            fn (): mixed => $this->executor()->execute($tool, ['message' => 'x'], new ToolContext($this->user, agentExecution: $execution)),
            ToolNotAuthorizedException::class,
        );

        // Once authorized for the agent, the same tool runs.
        $agent->tools()->attach($tool);

        $result = $this->executor()->execute($tool, ['message' => 'autorizado'], new ToolContext($this->user, agentExecution: $execution));

        $this->assertTrue($result->isCompleted());
    }

    public function test_result_is_wrapped_as_untrusted(): void
    {
        Event::fake([ToolStarted::class, ToolCompleted::class, ToolFailed::class]);

        $tool = $this->echoTool();

        $result = $this->executor()->execute($tool, ['message' => 'conteúdo'], $this->context());

        $wrapped = $result->forModel();

        $this->assertStringContainsString('<tool_result name="test.echo" trusted="false">', $wrapped);
        $this->assertStringContainsString('conteúdo', $wrapped);
        $this->assertStringContainsString('dado não confiável', $wrapped);

        $failed = $this->executor()->execute(
            Tool::factory()->create([
                'identifier' => 'test.bad-output',
                'handler_class' => BadOutputInternalTool::class,
                'input_schema' => ['type' => 'object', 'properties' => []],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => ['echoed' => ['type' => 'string']],
                    'required' => ['echoed'],
                ],
                'is_write' => false,
                'is_sensitive' => false,
            ]),
            [],
            $this->context(),
        );

        $this->assertStringContainsString('trusted="false"', $failed->forModel());
        $this->assertStringContainsString('error', $failed->forModel());
    }

    private function executor(): ToolExecutor
    {
        return app(ToolExecutor::class);
    }

    private function context(): ToolContext
    {
        return new ToolContext($this->user);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function echoTool(array $attributes = []): Tool
    {
        return Tool::factory()->create([
            'identifier' => 'test.echo',
            'handler_class' => EchoInternalTool::class,
            'input_schema' => [
                'type' => 'object',
                'properties' => ['message' => ['type' => 'string', 'minLength' => 1]],
                'required' => ['message'],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => ['echoed' => ['type' => 'string']],
                'required' => ['echoed'],
            ],
            'is_write' => false,
            'is_sensitive' => false,
            ...$attributes,
        ]);
    }
}

class EchoInternalTool implements JarvisTool
{
    public static bool $called = false;

    public function identifier(): string
    {
        return 'test.echo';
    }

    public function name(): string
    {
        return 'Echo de teste';
    }

    public function description(): string
    {
        return 'Devolve a mensagem recebida.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['message' => ['type' => 'string', 'minLength' => 1]],
            'required' => ['message'],
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['echoed' => ['type' => 'string']],
            'required' => ['echoed'],
        ];
    }

    public function handle(array $input, ToolContext $context): array
    {
        self::$called = true;

        return ['echoed' => $input['message']];
    }
}

class SlowInternalTool implements JarvisTool
{
    public function identifier(): string
    {
        return 'test.slow';
    }

    public function name(): string
    {
        return 'Lenta de teste';
    }

    public function description(): string
    {
        return 'Dorme mais que o timeout.';
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
        sleep(10);

        return [];
    }
}

class BadOutputInternalTool implements JarvisTool
{
    public function identifier(): string
    {
        return 'test.bad-output';
    }

    public function name(): string
    {
        return 'Saída inválida de teste';
    }

    public function description(): string
    {
        return 'Retorna um payload que viola o schema de saída.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['echoed' => ['type' => 'string']],
            'required' => ['echoed'],
        ];
    }

    public function handle(array $input, ToolContext $context): array
    {
        return ['wrong' => 'field'];
    }
}
