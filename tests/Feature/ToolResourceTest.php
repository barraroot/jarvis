<?php

namespace Tests\Feature;

use App\Filament\Resources\Tools\Pages\CreateTool;
use App\Filament\Resources\Tools\Pages\EditTool;
use App\Filament\Resources\Tools\Pages\ListTools;
use App\Models\AuditAction;
use App\Models\Tool;
use App\Models\ToolType;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class ToolResourceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $this->setUpSeedsLookups();

        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
    }

    public function test_invalid_json_schema_is_rejected(): void
    {
        Livewire::test(CreateTool::class)
            ->fillForm([
                'tool_type_id' => ToolType::idFor(ToolType::INTERNAL),
                'identifier' => 'internal.invalid_schema',
                'name' => 'Schema inválido',
                'description' => 'Ferramenta cujo schema de entrada não é um JSON Schema.',
                'input_schema' => ['type' => 'banana'],
                'output_schema' => ['type' => 'object', 'properties' => []],
                'timeout_seconds' => 30,
                'is_write' => true,
                'is_sensitive' => true,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['input_schema']);

        $this->assertDatabaseMissing('tools', ['identifier' => 'internal.invalid_schema']);

        // JSON que nem parseia também é rejeitado.
        Livewire::test(CreateTool::class)
            ->fillForm([
                'tool_type_id' => ToolType::idFor(ToolType::INTERNAL),
                'identifier' => 'internal.broken_json',
                'name' => 'JSON quebrado',
                'description' => 'Ferramenta cujo schema não é JSON.',
                'input_schema' => '{"type": ',
                'output_schema' => '{"type": "object"}',
                'timeout_seconds' => 30,
                'is_write' => true,
                'is_sensitive' => true,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['input_schema']);
    }

    public function test_mcp_tool_only_allows_classification_fields(): void
    {
        $tool = Tool::factory()->mcp()->create([
            'identifier' => 'mcp.erp.list_customers',
            'input_schema' => ['type' => 'object', 'properties' => []],
            'output_schema' => ['type' => 'object', 'properties' => []],
        ]);

        Livewire::test(EditTool::class, ['record' => $tool->getRouteKey()])
            ->assertFormFieldIsDisabled('identifier')
            ->assertFormFieldIsDisabled('name')
            ->assertFormFieldIsDisabled('description')
            ->assertFormFieldIsDisabled('input_schema')
            ->assertFormFieldIsDisabled('output_schema')
            ->assertFormFieldIsDisabled('timeout_seconds')
            ->assertFormFieldIsHidden('handler_class')
            ->assertFormFieldIsHidden('endpoint_url')
            ->assertFormFieldIsHidden('http_method')
            ->assertFormFieldIsHidden('credential')
            ->assertFormFieldIsEnabled('is_write')
            ->assertFormFieldIsEnabled('is_sensitive')
            ->assertFormFieldIsEnabled('is_active');

        // Classification changes are the only edits allowed and they stick.
        Livewire::test(EditTool::class, ['record' => $tool->getRouteKey()])
            ->fillForm([
                'is_write' => false,
                'is_sensitive' => false,
                'is_active' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $tool->refresh();

        $this->assertFalse($tool->is_write);
        $this->assertFalse($tool->is_sensitive);
        $this->assertSame('mcp.erp.list_customers', $tool->identifier);
    }

    public function test_tool_changes_are_audited(): void
    {
        Livewire::test(CreateTool::class)
            ->fillForm($this->validForm())
            ->call('create')
            ->assertHasNoFormErrors();

        $tool = Tool::query()->where('identifier', 'internal.audited')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'audit_action_id' => AuditAction::idFor(AuditAction::TOOL_CREATED),
            'user_id' => $this->admin->id,
            'subject_type' => (new Tool)->getMorphClass(),
            'subject_id' => $tool->id,
        ]);

        Livewire::test(EditTool::class, ['record' => $tool->getRouteKey()])
            ->fillForm([
                ...$this->validForm(),
                'name' => 'Ferramenta auditada renomeada',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('audit_logs', [
            'audit_action_id' => AuditAction::idFor(AuditAction::TOOL_UPDATED),
            'user_id' => $this->admin->id,
            'subject_type' => (new Tool)->getMorphClass(),
            'subject_id' => $tool->id,
        ]);

        Livewire::test(ListTools::class)
            ->callTableAction(DeleteAction::class, $tool);

        $this->assertSoftDeleted('tools', ['id' => $tool->id]);

        $this->assertDatabaseHas('audit_logs', [
            'audit_action_id' => AuditAction::idFor(AuditAction::TOOL_DELETED),
            'user_id' => $this->admin->id,
            'subject_type' => (new Tool)->getMorphClass(),
            'subject_id' => $tool->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validForm(): array
    {
        return [
            'tool_type_id' => ToolType::idFor(ToolType::INTERNAL),
            'identifier' => 'internal.audited',
            'name' => 'Ferramenta auditada',
            'description' => 'Ferramenta interna usada para testar a auditoria.',
            'input_schema' => ['type' => 'object', 'properties' => [], 'required' => []],
            'output_schema' => ['type' => 'object', 'properties' => []],
            'timeout_seconds' => 30,
            'is_write' => true,
            'is_sensitive' => true,
            'is_active' => true,
        ];
    }
}
