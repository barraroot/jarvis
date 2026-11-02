<?php

namespace Tests\Feature;

use App\Events\Tool\ToolCompleted;
use App\Events\Tool\ToolStarted;
use App\Models\ExecutionStatus;
use App\Models\McpServer;
use App\Models\McpToolDefinition;
use App\Models\Tool;
use App\Models\User;
use App\Tools\McpToolHandler;
use App\Tools\ToolContext;
use App\Tools\ToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\FakesMcpServer;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class McpToolHandlerTest extends TestCase
{
    use FakesMcpServer;
    use RefreshDatabase;
    use SeedsLookups;

    private const string URL = 'https://erp.example.com/mcp';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();
    }

    public function test_executes_remote_tool_with_input(): void
    {
        Event::fake([ToolStarted::class, ToolCompleted::class]);

        $user = User::factory()->create();
        $server = McpServer::factory()->global()->connected()->create(['url' => self::URL, 'credential' => 'erp-token']);
        $definition = McpToolDefinition::factory()->for($server, 'server')->create(['name' => 'list_overdue_customers']);
        $tool = Tool::factory()->mcp()->readOnly()->create([
            'mcp_tool_definition_id' => $definition->id,
            'input_schema' => ['type' => 'object', 'properties' => ['days' => ['type' => 'integer']], 'required' => ['days']],
            'output_schema' => ['type' => 'object'],
            'timeout_seconds' => 12,
            'is_active' => true,
        ]);

        $structured = true;

        $this->fakeMcpServer(self::URL, [], function (string $name, array $arguments) use (&$structured): array {
            if (! $structured) {
                return ['content' => [['type' => 'text', 'text' => 'pong']]];
            }

            return [
                'content' => [['type' => 'text', 'text' => 'ignored']],
                'structuredContent' => ['customers' => [['name' => 'ACME', 'days_overdue' => $arguments['days']]]],
            ];
        });

        $result = app(ToolExecutor::class)->execute($tool, ['days' => 30], new ToolContext($user));

        $this->assertTrue($result->isCompleted());
        $this->assertSame(['customers' => [['name' => 'ACME', 'days_overdue' => 30]]], $result->output);
        $this->assertSame(ExecutionStatus::COMPLETED, $result->execution->status->slug);

        Http::assertSent(fn (Request $request): bool => ($request->data()['method'] ?? null) === 'tools/call'
            && $request->data()['params']['name'] === 'list_overdue_customers'
            && $request->data()['params']['arguments'] === ['days' => 30]
            && $request->hasHeader('Authorization', 'Bearer erp-token'));

        // Plain-text results are wrapped; another user's personal connector is never reachable.
        $structured = false;

        $this->assertSame(['text' => 'pong'], app(McpToolHandler::class)->handle($tool, ['days' => 1], new ToolContext($user)));

        $server->update(['user_id' => User::factory()->create()->id]);

        $this->expectException(RuntimeException::class);

        app(McpToolHandler::class)->handle($tool->fresh(), ['days' => 1], new ToolContext($user));
    }
}
