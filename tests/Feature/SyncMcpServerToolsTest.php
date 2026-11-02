<?php

namespace Tests\Feature;

use App\Events\Connector\StatusChanged;
use App\Events\NotificationCreated;
use App\Jobs\SyncMcpServerTools;
use App\Models\ConnectorStatus;
use App\Models\McpServer;
use App\Models\McpToolDefinition;
use App\Models\Notification;
use App\Models\Tool;
use App\Models\ToolType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesMcpServer;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class SyncMcpServerToolsTest extends TestCase
{
    use FakesMcpServer;
    use RefreshDatabase;
    use SeedsLookups;

    private const string URL = 'https://erp.example.com/mcp';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->user = User::factory()->create();
    }

    public function test_discovers_and_creates_tools_as_write_sensitive_inactive(): void
    {
        Event::fake([StatusChanged::class, NotificationCreated::class]);

        $server = McpServer::factory()->for($this->user)->create(['name' => 'Meu ERP', 'url' => self::URL]);

        $this->fakeMcpServer(self::URL, [
            $this->mcpToolPayload('list_overdue_customers', 'Clientes em atraso'),
            $this->mcpToolPayload('send_reminder'),
        ]);

        SyncMcpServerTools::dispatchSync($server);

        $server->refresh();

        $this->assertSame(ConnectorStatus::CONNECTED, $server->status->slug);
        $this->assertNotNull($server->last_synced_at);
        $this->assertNotNull($server->last_checked_at);
        $this->assertNull($server->last_error);

        $definitions = $server->toolDefinitions()->orderBy('name')->get();

        $this->assertSame(['list_overdue_customers', 'send_reminder'], $definitions->pluck('name')->all());
        $this->assertTrue($definitions->every(fn (McpToolDefinition $definition): bool => $definition->is_available && $definition->last_seen_at !== null));

        $tool = Tool::query()->where('identifier', 'mcp.meu_erp.list_overdue_customers')->firstOrFail();

        $this->assertSame(ToolType::MCP, $tool->type->slug);
        $this->assertSame($definitions->first()->id, $tool->mcp_tool_definition_id);
        $this->assertSame('Clientes em atraso', $tool->description);
        $this->assertSame('string', $tool->input_schema['properties']['query']['type']);
        $this->assertTrue($tool->is_write);
        $this->assertTrue($tool->is_sensitive);
        $this->assertFalse($tool->is_active);
        $this->assertSame(2, Tool::query()->count());

        Event::assertDispatched(StatusChanged::class, fn (StatusChanged $event): bool => $event->server->is($server)
            && $event->previousStatus === ConnectorStatus::PENDING
            && $event->broadcastWith()['status'] === ConnectorStatus::CONNECTED);
        Event::assertNotDispatched(NotificationCreated::class);

        // A second sync with the same tools neither duplicates rows nor re-broadcasts.
        SyncMcpServerTools::dispatchSync($server);

        $this->assertSame(2, Tool::query()->count());
        $this->assertSame(2, McpToolDefinition::query()->count());
        Event::assertDispatchedTimes(StatusChanged::class, 1);
    }

    public function test_removed_tools_become_unavailable_and_inactive(): void
    {
        Event::fake([StatusChanged::class, NotificationCreated::class]);

        $server = McpServer::factory()->connected()->for($this->user)->create(['url' => self::URL]);

        $kept = McpToolDefinition::factory()->for($server, 'server')->create(['name' => 'list_overdue_customers']);
        $keptTool = Tool::factory()->mcp()->create(['mcp_tool_definition_id' => $kept->id, 'is_active' => true, 'is_write' => false, 'is_sensitive' => false]);

        $removed = McpToolDefinition::factory()->for($server, 'server')->create(['name' => 'delete_customer']);
        $removedTool = Tool::factory()->mcp()->create(['mcp_tool_definition_id' => $removed->id, 'is_active' => true]);

        $this->fakeMcpServer(self::URL, [$this->mcpToolPayload('list_overdue_customers')]);

        SyncMcpServerTools::dispatchSync($server);

        $this->assertFalse($removed->fresh()->is_available);
        $this->assertFalse($removedTool->fresh()->is_active);

        // The still-published tool keeps its explicit classification and activation.
        $this->assertTrue($kept->fresh()->is_available);
        $keptTool->refresh();
        $this->assertTrue($keptTool->is_active);
        $this->assertFalse($keptTool->is_write);
        $this->assertFalse($keptTool->is_sensitive);

        Event::assertNotDispatched(StatusChanged::class);
    }

    public function test_sync_failure_sets_error_status_and_broadcasts(): void
    {
        Event::fake([StatusChanged::class, NotificationCreated::class]);

        $server = McpServer::factory()->connected()->for($this->user)->create([
            'url' => self::URL,
            'credential' => 'very-secret-credential',
        ]);
        $syncedAt = $server->last_synced_at;

        Http::fake([
            self::URL => fn () => throw new ConnectionException('cURL error 7: Connection refused'),
        ]);

        SyncMcpServerTools::dispatchSync($server);

        $server->refresh();

        $this->assertSame(ConnectorStatus::ERROR, $server->status->slug);
        $this->assertStringContainsString('Connection refused', $server->last_error);
        $this->assertStringNotContainsString('very-secret-credential', $server->last_error);
        $this->assertEquals($syncedAt->toIso8601String(), $server->last_synced_at->toIso8601String());

        Event::assertDispatched(StatusChanged::class, fn (StatusChanged $event): bool => $event->previousStatus === ConnectorStatus::CONNECTED
            && $event->broadcastWith()['status'] === ConnectorStatus::ERROR
            && $event->broadcastOn()[0]->name === 'private-user.'.$this->user->id);

        $this->assertSame(1, Notification::query()->where('user_id', $this->user->id)->count());
    }
}
