<?php

namespace Tests\Feature;

use App\Ai\Agents\RuntimeAgent;
use App\Events\Agent\Completed;
use App\Events\Agent\Failed;
use App\Events\Agent\Started;
use App\Events\Assistant\AssistantResponseChunk;
use App\Exceptions\AgentUnavailableException;
use App\Models\Agent;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Conversation;
use App\Models\ExecutionStatus;
use App\Models\Message;
use App\Models\Tool;
use App\Models\User;
use App\Services\AgentRunner;
use App\Services\AgentRuntimeFactory;
use App\Services\RunOptions;
use App\Tools\Internal\CurrentTimeTool;
use App\Tools\ToolContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Ai;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\TextResponse;
use RuntimeException;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class AgentRunnerTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private AiProvider $provider;

    private AiModel $defaultModel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->provider = AiProvider::factory()->create();
        $this->defaultModel = AiModel::factory()->defaultText()->for($this->provider, 'provider')->create();
    }

    public function test_records_execution_and_emits_started_and_completed(): void
    {
        Event::fake();

        $user = User::factory()->create();
        $agent = Agent::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        $assistantMessage = Message::factory()->for($conversation)->for($user)->fromAssistant()
            ->create(['execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::PENDING), 'content' => '']);

        Ai::fakeAgent(RuntimeAgent::class, function (string $prompt, mixed $attachments, mixed $provider, string $model): mixed {
            return new TextResponse('Olá, operador.', new TextUsage(30, 12), new Meta($provider->name(), $model));
        });

        $execution = app(AgentRunner::class)->run(
            $agent,
            $user,
            'Diga olá.',
            new RunOptions($conversation, $assistantMessage),
        );

        $this->assertSame(ExecutionStatus::idFor(ExecutionStatus::COMPLETED), $execution->execution_status_id);
        $this->assertNotNull($execution->started_at);
        $this->assertNotNull($execution->finished_at);
        $this->assertSame(0, $execution->depth);
        $this->assertNull($execution->parent_agent_execution_id);
        $this->assertSame(0, $execution->tool_call_count);
        $this->assertSame('Olá, operador.', $execution->meta['output']);

        Event::assertDispatched(Started::class);
        Event::assertDispatched(Completed::class);
        Event::assertDispatched(AssistantResponseChunk::class);

        // The pending assistant message flips to running on the first chunk and stays completed.
        $this->assertSame(
            ExecutionStatus::idFor(ExecutionStatus::RUNNING),
            $assistantMessage->fresh()->execution_status_id,
        );

        $this->assertDatabaseHas('usage_records', [
            'user_id' => $user->id,
            'ai_model_id' => $this->defaultModel->id,
            'agent_execution_id' => $execution->id,
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::COMPLETED),
        ]);
    }

    public function test_exceeding_max_tool_calls_fails_execution(): void
    {
        Event::fake();

        $user = User::factory()->create();
        $agent = Agent::factory()->create(['max_tool_calls' => 1]);

        $tool = Tool::factory()->internal()->create([
            'identifier' => 'time.current',
            'handler_class' => CurrentTimeTool::class,
            'is_write' => false,
            'is_sensitive' => false,
        ]);
        $agent->tools()->attach($tool);

        // Every step answers with another tool call, forever.
        Ai::fakeAgent(RuntimeAgent::class, fn (): ToolCall => new ToolCall('call-1', 'time.current', []));

        $execution = app(AgentRunner::class)->run($agent, $user, 'Que horas são?', new RunOptions);

        $this->assertSame(ExecutionStatus::idFor(ExecutionStatus::FAILED), $execution->execution_status_id);
        $this->assertSame(2, $execution->tool_call_count);
        $this->assertStringContainsString('excedeu o limite de 1 chamadas', $execution->error);
        $this->assertNotNull($execution->finished_at);

        Event::assertDispatched(Started::class);
        Event::assertDispatched(Failed::class);
        Event::assertNotDispatched(Completed::class);

        $this->assertDatabaseHas('usage_records', [
            'agent_execution_id' => $execution->id,
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::FAILED),
        ]);
    }

    public function test_timeout_marks_timed_out(): void
    {
        Event::fake();

        $user = User::factory()->create();
        $agent = Agent::factory()->create(['timeout_seconds' => 30]);

        Ai::fakeAgent(RuntimeAgent::class, function (): never {
            throw new RuntimeException('Connection timed out after 30 seconds.');
        });

        $execution = app(AgentRunner::class)->run($agent, $user, 'Responda.', new RunOptions);

        $this->assertSame(ExecutionStatus::idFor(ExecutionStatus::TIMED_OUT), $execution->execution_status_id);
        $this->assertStringContainsString('timed out', $execution->error);

        Event::assertDispatched(Failed::class, function (Failed $event) use ($execution): bool {
            return $event->broadcastWith()['execution_id'] === $execution->id
                && $event->broadcastWith()['status'] === ExecutionStatus::TIMED_OUT;
        });
    }

    public function test_inactive_agent_cannot_run(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->inactive()->create();

        $this->expectException(AgentUnavailableException::class);

        app(AgentRunner::class)->run($agent, $user, 'Responda.', new RunOptions);

        $this->assertDatabaseCount('agent_executions', 0);
    }

    public function test_only_authorized_tools_are_exposed_to_the_model(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->create();

        $authorizedRead = Tool::factory()->internal()->create([
            'identifier' => 'internal.read',
            'handler_class' => CurrentTimeTool::class,
            'is_write' => false,
            'is_sensitive' => false,
        ]);
        $authorizedWrite = Tool::factory()->internal()->create([
            'identifier' => 'internal.write',
            'handler_class' => CurrentTimeTool::class,
        ]);
        $unauthorized = Tool::factory()->internal()->create([
            'identifier' => 'internal.unauthorized',
            'handler_class' => CurrentTimeTool::class,
            'is_write' => false,
            'is_sensitive' => false,
        ]);

        $agent->tools()->attach([$authorizedRead->id, $authorizedWrite->id]);

        $runtimeAgent = app(AgentRuntimeFactory::class)->make(
            $agent,
            new ToolContext($user),
            [],
        );

        $names = array_map(
            static fn (object $tool): string => method_exists($tool, 'name') ? $tool->name() : (string) $tool,
            is_array($runtimeAgent->tools()) ? $runtimeAgent->tools() : iterator_to_array($runtimeAgent->tools()),
        );

        sort($names);

        $this->assertSame(['internal.read', 'internal.write'], $names);

        // The instructions carry name, objective and the pt-BR agent instructions.
        $this->assertStringContainsString($agent->name, $runtimeAgent->instructions());
        $this->assertStringContainsString($agent->objective, $runtimeAgent->instructions());
        $this->assertStringContainsString($agent->instructions, $runtimeAgent->instructions());

        $this->assertDatabaseCount('tools', 3);
        $this->assertTrue($unauthorized->fresh()->is_active);
    }
}
