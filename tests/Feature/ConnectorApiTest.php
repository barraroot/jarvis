<?php

namespace Tests\Feature;

use App\Jobs\SyncMcpServerTools;
use App\Models\AuditAction;
use App\Models\AuditLog;
use App\Models\ConnectorStatus;
use App\Models\McpServer;
use App\Models\McpToolDefinition;
use App\Models\Tool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class ConnectorApiTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        Queue::fake();

        $this->user = User::factory()->create();

        $this->actingAs($this->user);
    }

    public function test_lists_global_and_own_connectors_only(): void
    {
        $global = McpServer::factory()->global()->connected()->create(['name' => 'ERP Global']);
        McpServer::factory()->global()->inactive()->create(['name' => 'Desligado']);
        $own = McpServer::factory()->errored()->for($this->user)->create(['name' => 'Meu CRM']);
        McpServer::factory()->for(User::factory())->create(['name' => 'CRM de outro']);

        McpToolDefinition::factory()->for($global, 'server')->count(2)->create();
        $definition = McpToolDefinition::factory()->for($global, 'server')->create();
        Tool::factory()->mcp()->create(['mcp_tool_definition_id' => $definition->id, 'is_active' => true]);
        McpToolDefinition::factory()->unavailable()->for($global, 'server')->create();

        $this->getJson('/api/v1/connectors')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $global->id)
            ->assertJsonPath('data.0.scope', 'global')
            ->assertJsonPath('data.0.status', ConnectorStatus::CONNECTED)
            ->assertJsonPath('data.0.tools_count', 3)
            ->assertJsonPath('data.0.active_tools_count', 1)
            ->assertJsonPath('data.0.can_manage', false)
            ->assertJsonPath('data.0.last_synced_at', fn ($value): bool => $value !== null)
            ->assertJsonPath('data.1.id', $own->id)
            ->assertJsonPath('data.1.scope', 'personal')
            ->assertJsonPath('data.1.status', ConnectorStatus::ERROR)
            ->assertJsonPath('data.1.last_error', 'Connection refused')
            ->assertJsonPath('data.1.can_manage', true)
            ->assertJsonPath('data.1.can_classify', true);

        // Another user's personal connector is out of reach.
        $foreign = McpServer::query()->where('name', 'CRM de outro')->firstOrFail();

        $this->getJson("/api/v1/connectors/{$foreign->id}/tools")->assertForbidden();
        $this->deleteJson("/api/v1/connectors/{$foreign->id}")->assertForbidden();
    }

    public function test_operator_cannot_edit_global_connector(): void
    {
        $global = McpServer::factory()->global()->connected()->create(['name' => 'ERP Global']);
        $definition = McpToolDefinition::factory()->for($global, 'server')->create();
        $tool = Tool::factory()->mcp()->create(['mcp_tool_definition_id' => $definition->id, 'is_active' => false]);

        $this->getJson("/api/v1/connectors/{$global->id}/tools")
            ->assertOk()
            ->assertJsonPath('data.0.id', $tool->id);

        $this->patchJson("/api/v1/connectors/{$global->id}", ['name' => 'Renomeado'])->assertForbidden();
        $this->deleteJson("/api/v1/connectors/{$global->id}")->assertForbidden();
        $this->postJson("/api/v1/connectors/{$global->id}/resync")->assertForbidden();
        $this->patchJson("/api/v1/connectors/{$global->id}/tools/{$tool->id}", ['is_active' => true])->assertForbidden();

        $this->assertSame('ERP Global', $global->fresh()->name);
        $this->assertFalse($tool->fresh()->is_active);
        Queue::assertNothingPushed();
    }

    public function test_credential_is_never_returned(): void
    {
        $credential = 'personal-secret-credential-123';

        $response = $this->postJson('/api/v1/connectors', [
            'name' => 'Meu ERP',
            'url' => 'https://erp.example.com/mcp',
            'credential' => $credential,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.scope', 'personal')
            ->assertJsonPath('data.status', ConnectorStatus::PENDING)
            ->assertJsonPath('data.has_credential', true)
            ->assertJsonMissingPath('data.credential');

        $this->assertStringNotContainsString($credential, $response->getContent());

        $connector = McpServer::query()->findOrFail($response->json('data.id'));

        $this->assertSame($this->user->id, $connector->user_id);
        $this->assertSame($credential, $connector->credential);
        Queue::assertPushed(SyncMcpServerTools::class, fn (SyncMcpServerTools $job): bool => $job->server->is($connector));

        $this->assertStringNotContainsString($credential, $this->getJson('/api/v1/connectors')->assertOk()->getContent());

        // A blank credential on edit keeps the stored one; the audit trail never holds it.
        $this->patchJson("/api/v1/connectors/{$connector->id}", ['name' => 'ERP Pessoal', 'credential' => ''])
            ->assertOk()
            ->assertJsonPath('data.name', 'ERP Pessoal');

        $this->assertSame($credential, $connector->fresh()->credential);
        $this->assertStringNotContainsString($credential, AuditLog::query()->get()->toJson());
        $this->assertTrue(AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::CONNECTOR_UPDATED))->exists());

        // URL rules: no stdio, no private hosts.
        $this->postJson('/api/v1/connectors', ['name' => 'Local', 'url' => 'http://127.0.0.1:8000/mcp'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');
        $this->postJson('/api/v1/connectors', ['name' => 'Stdio', 'url' => 'stdio://npx server'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');

        // Resync dispatches discovery; removal deactivates the connector's tools.
        $this->postJson("/api/v1/connectors/{$connector->id}/resync")->assertStatus(202);
        Queue::assertPushed(SyncMcpServerTools::class, 2);

        $definition = McpToolDefinition::factory()->for($connector, 'server')->create();
        $tool = Tool::factory()->mcp()->create(['mcp_tool_definition_id' => $definition->id, 'is_active' => true]);

        $this->deleteJson("/api/v1/connectors/{$connector->id}")->assertOk();

        $this->assertSoftDeleted($connector);
        $this->assertFalse($tool->fresh()->is_active);
        $this->assertTrue(AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::CONNECTOR_DELETED))->exists());

        // The name is free again after removal.
        $this->postJson('/api/v1/connectors', ['name' => 'ERP Pessoal', 'url' => 'https://erp.example.com/mcp'])->assertCreated();
    }

    public function test_owner_can_classify_personal_tools(): void
    {
        $own = McpServer::factory()->connected()->for($this->user)->create();
        $definition = McpToolDefinition::factory()->for($own, 'server')->create(['name' => 'list_customers']);
        $tool = Tool::factory()->mcp()->create(['mcp_tool_definition_id' => $definition->id, 'is_active' => false]);

        $this->patchJson("/api/v1/connectors/{$own->id}/tools/{$tool->id}", [
            'is_write' => false,
            'is_sensitive' => false,
            'is_active' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $tool->id)
            ->assertJsonPath('data.name', 'list_customers')
            ->assertJsonPath('data.is_write', false)
            ->assertJsonPath('data.is_sensitive', false)
            ->assertJsonPath('data.is_active', true);

        $tool->refresh();

        $this->assertFalse($tool->is_write);
        $this->assertFalse($tool->is_sensitive);
        $this->assertTrue($tool->is_active);

        // A tool from another connector cannot be classified through this one.
        $otherDefinition = McpToolDefinition::factory()->for(McpServer::factory()->for($this->user), 'server')->create();
        $otherTool = Tool::factory()->mcp()->create(['mcp_tool_definition_id' => $otherDefinition->id]);

        $this->patchJson("/api/v1/connectors/{$own->id}/tools/{$otherTool->id}", ['is_active' => true])->assertNotFound();

        // Tools removed on the server cannot be activated.
        $definition->update(['is_available' => false]);
        $tool->update(['is_active' => false]);

        $this->patchJson("/api/v1/connectors/{$own->id}/tools/{$tool->id}", ['is_active' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');
    }
}
