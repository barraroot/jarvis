<?php

namespace Tests\Feature;

use App\Filament\Resources\Agents\Pages\CreateAgent;
use App\Filament\Resources\Agents\Pages\EditAgent;
use App\Filament\Resources\Agents\Pages\ListAgents;
use App\Models\Agent;
use App\Models\AuditAction;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class AgentResourceTest extends TestCase
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

    public function test_limits_are_validated_within_ranges(): void
    {
        Livewire::test(CreateAgent::class)
            ->fillForm([
                ...$this->validForm(),
                'slug' => 'out-of-range',
                'max_delegation_depth' => 6,
                'max_tool_calls' => 200,
                'timeout_seconds' => 10,
            ])
            ->call('create')
            ->assertHasFormErrors(['max_delegation_depth', 'max_tool_calls', 'timeout_seconds']);

        $this->assertDatabaseMissing('agents', ['slug' => 'out-of-range']);

        Livewire::test(CreateAgent::class)
            ->fillForm($this->validForm())
            ->call('create')
            ->assertHasNoFormErrors();

        $agent = Agent::query()->where('slug', 'audit-agent')->firstOrFail();

        $this->assertSame(3, $agent->max_delegation_depth);
        $this->assertSame(20, $agent->max_tool_calls);
        $this->assertSame(900, $agent->timeout_seconds);
    }

    public function test_cannot_deactivate_default_agent_without_replacement(): void
    {
        $default = Agent::factory()->default()->create();

        // Deactivating the default directly is rejected...
        Livewire::test(EditAgent::class, ['record' => $default->getRouteKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasFormErrors(['is_active']);

        $this->assertTrue($default->fresh()->is_active);

        // ...promoting another agent to default first makes room for it.
        Livewire::test(CreateAgent::class)
            ->fillForm([
                ...$this->validForm(),
                'slug' => 'replacement',
                'is_default' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertFalse($default->fresh()->is_default);

        Livewire::test(EditAgent::class, ['record' => $default->getRouteKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($default->fresh()->is_active);
        $this->assertSame(1, Agent::query()->where('is_default', true)->count());
        $this->assertSame('replacement', Agent::query()->where('is_default', true)->firstOrFail()->slug);
    }

    public function test_agent_changes_are_audited(): void
    {
        Livewire::test(CreateAgent::class)
            ->fillForm($this->validForm())
            ->call('create')
            ->assertHasNoFormErrors();

        $agent = Agent::query()->where('slug', 'audit-agent')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'audit_action_id' => AuditAction::idFor(AuditAction::AGENT_CREATED),
            'user_id' => $this->admin->id,
            'subject_type' => (new Agent)->getMorphClass(),
            'subject_id' => $agent->id,
        ]);

        Livewire::test(EditAgent::class, ['record' => $agent->getRouteKey()])
            ->fillForm([
                ...$this->validForm(),
                'name' => 'Agente auditado renomeado',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('audit_logs', [
            'audit_action_id' => AuditAction::idFor(AuditAction::AGENT_UPDATED),
            'user_id' => $this->admin->id,
            'subject_type' => (new Agent)->getMorphClass(),
            'subject_id' => $agent->id,
        ]);

        Livewire::test(ListAgents::class)
            ->callTableAction(DeleteAction::class, $agent);

        $this->assertSoftDeleted('agents', ['id' => $agent->id]);

        $this->assertDatabaseHas('audit_logs', [
            'audit_action_id' => AuditAction::idFor(AuditAction::AGENT_DELETED),
            'user_id' => $this->admin->id,
            'subject_type' => (new Agent)->getMorphClass(),
            'subject_id' => $agent->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validForm(): array
    {
        return [
            'name' => 'Agente Auditado',
            'slug' => 'audit-agent',
            'description' => 'Agente usado pelo teste de auditoria.',
            'objective' => 'Servir de alvo de auditoria.',
            'instructions' => 'Instruções de teste.',
            'ai_model_id' => null,
            'tools' => [],
            'max_delegation_depth' => 3,
            'max_tool_calls' => 20,
            'timeout_seconds' => 900,
            'is_default' => false,
            'is_active' => true,
        ];
    }
}
