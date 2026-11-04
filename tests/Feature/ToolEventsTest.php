<?php

namespace Tests\Feature;

use App\Events\Tool\ToolCompleted;
use App\Events\Tool\ToolFailed;
use App\Events\Tool\ToolStarted;
use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\ExecutionStatus;
use App\Models\McpServer;
use App\Models\McpToolDefinition;
use App\Models\Task;
use App\Models\Tool;
use App\Models\ToolExecution;
use App\Models\User;
use App\Tools\ToolContext;
use App\Tools\ToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\FakesMcpServer;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class ToolEventsTest extends TestCase
{
    use FakesMcpServer;
    use RefreshDatabase;
    use SeedsLookups;

    private const string URL = 'https://blog.example.com/mcp';

    public function test_tool_events_are_broadcast_on_private_channel(): void
    {
        $this->setUpSeedsLookups();

        Event::fake();

        $user = User::factory()->create();
        $tool = Tool::factory()->create();
        $execution = ToolExecution::factory()->for($user)->for($tool)->running()->create();

        ToolStarted::dispatch($user, $tool, $execution);
        ToolCompleted::dispatch($user, $tool, $execution);
        ToolFailed::dispatch($user, $tool, $execution->fresh());

        foreach ([ToolStarted::class, ToolCompleted::class, ToolFailed::class] as $eventClass) {
            Event::assertDispatched($eventClass, fn ($event): bool => $event->broadcastOn()->name === 'private-user.'.$user->id);
        }

        // CT-03 is additive: the original keys keep their values.
        Event::assertDispatched(ToolStarted::class, fn (ToolStarted $event): bool => $event->broadcastAs() === 'tool.started'
            && array_intersect_key($event->broadcastWith(), array_flip(['tool', 'execution_id', 'status'])) === [
                'tool' => $tool->identifier,
                'execution_id' => $execution->id,
                'status' => 'running',
            ]);

        Event::assertDispatched(ToolCompleted::class, fn (ToolCompleted $event): bool => $event->broadcastAs() === 'tool.completed'
            && $event->broadcastWith()['status'] === 'completed');

        Event::assertDispatched(ToolFailed::class, fn (ToolFailed $event): bool => $event->broadcastAs() === 'tool.failed'
            && $event->broadcastWith()['status'] === ExecutionStatus::RUNNING);
    }

    public function test_started_payload_has_task_and_display_name_for_mcp(): void
    {
        $this->setUpSeedsLookups();

        Event::fake([ToolStarted::class, ToolCompleted::class]);

        [$user, $tool, $agentExecution] = $this->mcpToolInsideTask();

        $this->fakeMcpServer(self::URL, [], fn (string $name, array $arguments): array => [
            'content' => [['type' => 'text', 'text' => 'ignored']],
            'structuredContent' => ['posts' => [['title' => 'Aula de inglês']]],
        ]);

        $result = app(ToolExecutor::class)->execute($tool, ['query' => 'aula de inglês'], new ToolContext($user, $agentExecution->task, $agentExecution));

        $this->assertTrue($result->isCompleted());

        Event::assertDispatched(ToolStarted::class, function (ToolStarted $event) use ($agentExecution, $result): bool {
            $payload = $event->broadcastWith();

            $this->assertTrue($event->tool->relationLoaded('mcpToolDefinition'));
            $this->assertTrue($event->tool->mcpToolDefinition->relationLoaded('server'));
            $this->assertTrue($event->execution->relationLoaded('agentExecution'));
            $this->assertTrue($event->execution->relationLoaded('taskStep'));

            return $payload['task_id'] === $agentExecution->task_id
                && $payload['agent_execution_id'] === $agentExecution->id
                && $payload['execution_id'] === $result->execution->id
                && $payload['display_name'] === 'Beer And Code · search_posts'
                && $payload['input_summary'] === '{"query":"aula de inglês"}'
                && $payload['output_bytes'] === null
                && $payload['started_at'] !== null;
        });
    }

    public function test_input_summary_strips_sensitive_keys_and_limits_120(): void
    {
        $this->setUpSeedsLookups();

        Event::fake();

        $user = User::factory()->create();
        $tool = Tool::factory()->create(['name' => 'Busca HTTP']);
        $execution = ToolExecution::factory()->for($user)->for($tool)->running()->create([
            'input' => [
                'api_key' => 'sk-segredo-123',
                'auth' => ['access_token' => 'tok-segredo-456', 'password' => 'senha-789'],
                'query' => str_repeat('palavra ', 40),
            ],
        ]);

        ToolStarted::dispatch($user, $tool, $execution);

        Event::assertDispatched(ToolStarted::class, function (ToolStarted $event): bool {
            $payload = $event->broadcastWith();

            $this->assertSame('Busca HTTP', $payload['display_name']);
            $this->assertNull($payload['task_id']);
            $this->assertLessThanOrEqual(120, mb_strlen($payload['input_summary']));
            $this->assertStringStartsWith('{"auth":[],"query":"palavra', $payload['input_summary']);

            foreach (['sk-segredo-123', 'tok-segredo-456', 'senha-789', 'api_key', 'password'] as $secret) {
                $this->assertStringNotContainsString($secret, $payload['input_summary']);
            }

            return true;
        });
    }

    public function test_completed_payload_has_output_bytes(): void
    {
        $this->setUpSeedsLookups();

        Event::fake([ToolStarted::class, ToolCompleted::class, ToolFailed::class]);

        [$user, $tool, $agentExecution] = $this->mcpToolInsideTask();

        $output = ['posts' => [['title' => 'Aula de inglês', 'url' => 'https://beerandcode.com.br/aula']]];

        $this->fakeMcpServer(self::URL, [], fn (string $name, array $arguments): array => [
            'content' => [['type' => 'text', 'text' => 'ignored']],
            'structuredContent' => $output,
        ]);

        app(ToolExecutor::class)->execute($tool, ['query' => 'aula'], new ToolContext($user, $agentExecution->task, $agentExecution));

        Event::assertDispatched(ToolCompleted::class, function (ToolCompleted $event) use ($output, $agentExecution): bool {
            $payload = $event->broadcastWith();

            return $payload['status'] === 'completed'
                && $payload['output_bytes'] === strlen((string) json_encode($output))
                && $payload['task_id'] === $agentExecution->task_id
                && $payload['finished_at'] !== null;
        });

        // `output_bytes` is only sent on completion.
        $failed = ToolExecution::factory()->for($user)->for($tool)->failed()->create(['output' => $output]);
        ToolFailed::dispatch($user, $tool, $failed);

        Event::assertDispatched(ToolFailed::class, fn (ToolFailed $event): bool => $event->broadcastWith()['output_bytes'] === null);
    }

    /**
     * A read-only MCP tool authorized for an agent running inside a task.
     *
     * @return array{0: User, 1: Tool, 2: AgentExecution}
     */
    private function mcpToolInsideTask(): array
    {
        $user = User::factory()->create();
        $server = McpServer::factory()->global()->connected()->create(['name' => 'Beer And Code', 'url' => self::URL]);
        $definition = McpToolDefinition::factory()->for($server, 'server')->create(['name' => 'search_posts']);
        $tool = Tool::factory()->mcp()->readOnly()->create([
            'mcp_tool_definition_id' => $definition->id,
            'input_schema' => ['type' => 'object'],
            'output_schema' => ['type' => 'object'],
            'is_active' => true,
        ]);

        $agent = Agent::factory()->create();
        $agent->tools()->attach($tool);

        $task = Task::factory()->running()->for($user)->for($agent)->create();
        $agentExecution = AgentExecution::factory()->for($user)->for($agent)->create(['task_id' => $task->id]);

        return [$user, $tool, $agentExecution];
    }
}
