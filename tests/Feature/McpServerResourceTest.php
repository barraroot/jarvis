<?php

namespace Tests\Feature;

use App\Filament\Resources\McpServers\Pages\CreateMcpServer;
use App\Filament\Resources\McpServers\Pages\EditMcpServer;
use App\Filament\Resources\McpServers\Pages\ListMcpServers;
use App\Filament\Resources\McpServers\RelationManagers\ToolDefinitionsRelationManager;
use App\Jobs\SyncMcpServerTools;
use App\Models\AuditAction;
use App\Models\AuditLog;
use App\Models\ConnectorStatus;
use App\Models\McpServer;
use App\Models\McpToolDefinition;
use App\Models\Tool;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class McpServerResourceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $this->setUpSeedsLookups();

        Queue::fake();

        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
    }

    public function test_admin_creates_global_connector_and_sync_is_dispatched(): void
    {
        Livewire::test(CreateMcpServer::class)
            ->fillForm([
                'name' => 'ERP da empresa',
                'url' => 'https://erp.example.com/mcp',
                'credential' => 'global-secret-token-4321',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $server = McpServer::query()->where('name', 'ERP da empresa')->firstOrFail();

        $this->assertNull($server->user_id);
        $this->assertSame('global-secret-token-4321', $server->credential);
        $this->assertSame(ConnectorStatus::PENDING, $server->status->slug);
        Queue::assertPushed(SyncMcpServerTools::class, fn (SyncMcpServerTools $job): bool => $job->server->is($server));
        $this->assertTrue(AuditLog::query()
            ->where('audit_action_id', AuditAction::idFor(AuditAction::CONNECTOR_CREATED))
            ->where('subject_id', $server->id)
            ->exists());

        // Private hosts and stdio are rejected by the form.
        Livewire::test(CreateMcpServer::class)
            ->fillForm(['name' => 'Local', 'url' => 'http://192.168.0.10/mcp', 'is_active' => true])
            ->call('create')
            ->assertHasFormErrors(['url']);

        // The credential is masked everywhere in the panel.
        $edit = $this->get("/admin/mcp-servers/{$server->id}/edit")->assertOk();
        $this->assertStringNotContainsString('global-secret-token-4321', $edit->getContent());
        $this->assertStringContainsString('****4321', $edit->getContent());
        $this->assertStringNotContainsString('global-secret-token-4321', $this->get('/admin/mcp-servers')->assertOk()->getContent());

        // "Sincronizar" queues a new discovery.
        Livewire::test(ListMcpServers::class)
            ->callTableAction('sync', $server)
            ->assertHasNoTableActionErrors();

        Queue::assertPushed(SyncMcpServerTools::class, 2);

        // Personal connectors are visible but read-only.
        $personal = McpServer::factory()->for(User::factory())->create(['credential' => 'personal-secret-9999']);

        $view = $this->get("/admin/mcp-servers/{$personal->id}")->assertOk();
        $this->assertStringNotContainsString('personal-secret-9999', $view->getContent());
        $this->get("/admin/mcp-servers/{$personal->id}/edit")->assertForbidden();

        Livewire::test(ListMcpServers::class)
            ->assertCanSeeTableRecords([$server, $personal])
            ->assertTableActionHidden('edit', $personal)
            ->assertTableActionHidden('sync', $personal)
            ->assertTableActionVisible('edit', $server);
    }

    public function test_changes_are_audited(): void
    {
        $server = McpServer::factory()->global()->connected()->create([
            'name' => 'ERP',
            'url' => 'https://erp.example.com/mcp',
            'credential' => 'original-credential-1111',
        ]);

        Livewire::test(EditMcpServer::class, ['record' => $server->getRouteKey()])
            ->fillForm([
                'name' => 'ERP Corporativo',
                'url' => 'https://erp.example.com/mcp',
                'credential' => '',
                'is_active' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $server->refresh();
        $this->assertSame('ERP Corporativo', $server->name);
        $this->assertSame('original-credential-1111', $server->credential);

        $updated = AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::CONNECTOR_UPDATED))->firstOrFail();
        $this->assertSame(['name'], $updated->properties['changed']);
        $this->assertSame($this->admin->id, $updated->user_id);
        Queue::assertNothingPushed();

        // A new credential is audited without its value and triggers a resync.
        Livewire::test(EditMcpServer::class, ['record' => $server->getRouteKey()])
            ->fillForm([
                'name' => 'ERP Corporativo',
                'url' => 'https://erp.example.com/mcp',
                'credential' => 'rotated-credential-2222',
                'is_active' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('rotated-credential-2222', $server->fresh()->credential);
        Queue::assertPushed(SyncMcpServerTools::class, 1);

        // Classifying a global tool is audited as a tool update.
        $definition = McpToolDefinition::factory()->for($server, 'server')->create();
        $tool = Tool::factory()->mcp()->create(['mcp_tool_definition_id' => $definition->id, 'is_active' => false]);

        Livewire::test(ToolDefinitionsRelationManager::class, [
            'ownerRecord' => $server,
            'pageClass' => EditMcpServer::class,
        ])
            ->callTableAction('classify', $definition, data: [
                'is_write' => false,
                'is_sensitive' => false,
                'is_active' => true,
            ])
            ->assertHasNoTableActionErrors();

        $tool->refresh();
        $this->assertFalse($tool->is_write);
        $this->assertTrue($tool->is_active);
        $this->assertTrue(AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::TOOL_UPDATED))->exists());

        Livewire::test(EditMcpServer::class, ['record' => $server->getRouteKey()])
            ->callAction(DeleteAction::class)
            ->assertHasNoActionErrors();

        $this->assertSoftDeleted($server);
        $this->assertFalse($tool->fresh()->is_active);

        $created = McpServer::query()->count();
        $this->assertSame(0, $created);
        $this->assertTrue(AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::CONNECTOR_DELETED))->exists());
        $this->assertStringNotContainsString('credential-', AuditLog::query()->get()->toJson());
    }
}
