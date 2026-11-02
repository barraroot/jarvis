<?php

namespace Tests\Feature;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Models\Agent;
use App\Models\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class AuditLogResourceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        Filament::setCurrentPanel('admin');

        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
    }

    public function test_audit_logs_are_read_only_in_admin(): void
    {
        $log = AuditLog::factory()->for($this->admin)->action(AuditAction::SETTING_UPDATED)->create([
            'properties' => ['key' => 'brain.max_tool_calls', 'old' => 20, 'new' => 30],
        ]);

        $this->get('/admin/audit-logs')->assertOk()->assertSee('setting_updated');

        $this->get('/admin/audit-logs/'.$log->id)
            ->assertOk()
            ->assertSee('brain.max_tool_calls')
            ->assertSee('&quot;new&quot;', false);

        Livewire::test(ListAuditLogs::class)
            ->assertCanSeeTableRecords([$log])
            ->assertTableActionExists('view')
            ->assertTableActionDoesNotExist(EditAction::class)
            ->assertTableActionDoesNotExist(DeleteAction::class)
            ->assertTableBulkActionDoesNotExist('delete');

        Livewire::test(ViewAuditLog::class, ['record' => $log->getRouteKey()])
            ->assertActionDoesNotExist('edit')
            ->assertActionDoesNotExist('delete');

        $this->assertFalse(AuditLogResource::hasPage('create'));
        $this->assertFalse(AuditLogResource::hasPage('edit'));
        $this->assertFalse(AuditLogResource::canCreate());
        $this->assertFalse(AuditLogResource::canEdit($log));
        $this->assertFalse(AuditLogResource::canDelete($log));
        $this->assertFalse(AuditLogResource::canDeleteAny());

        $this->assertTrue(Gate::forUser($this->admin)->allows('viewAny', AuditLog::class));
        $this->assertFalse(Gate::forUser($this->admin)->allows('update', $log));
        $this->assertFalse(Gate::forUser($this->admin)->allows('delete', $log));

        // Operators cannot read the trail at all.
        $operator = User::factory()->create();
        $this->assertFalse(Gate::forUser($operator)->allows('viewAny', AuditLog::class));
    }

    public function test_filters_by_user_action_and_period(): void
    {
        $alice = User::factory()->create(['name' => 'Alice']);
        $agent = Agent::factory()->create();

        $aliceLogin = AuditLog::factory()->for($alice)->action(AuditAction::LOGIN_SUCCEEDED)->create([
            'created_at' => now()->subDays(10),
        ]);
        $adminAgentUpdate = AuditLog::factory()->for($this->admin)->action(AuditAction::AGENT_UPDATED)->about($agent)->create([
            'created_at' => now()->subDay(),
        ]);
        $adminLogin = AuditLog::factory()->for($this->admin)->action(AuditAction::LOGIN_SUCCEEDED)->create([
            'created_at' => now(),
        ]);

        Livewire::test(ListAuditLogs::class)
            ->assertCanSeeTableRecords([$aliceLogin, $adminAgentUpdate, $adminLogin])
            ->filterTable('user', $alice->id)
            ->assertCanSeeTableRecords([$aliceLogin])
            ->assertCanNotSeeTableRecords([$adminAgentUpdate, $adminLogin]);

        Livewire::test(ListAuditLogs::class)
            ->filterTable('action', AuditAction::idFor(AuditAction::LOGIN_SUCCEEDED))
            ->assertCanSeeTableRecords([$aliceLogin, $adminLogin])
            ->assertCanNotSeeTableRecords([$adminAgentUpdate]);

        Livewire::test(ListAuditLogs::class)
            ->filterTable('period', ['from' => now()->subDays(2)->toDateString(), 'until' => now()->subDay()->toDateString()])
            ->assertCanSeeTableRecords([$adminAgentUpdate])
            ->assertCanNotSeeTableRecords([$aliceLogin, $adminLogin]);

        Livewire::test(ListAuditLogs::class)
            ->filterTable('subject', ['subject_type' => $agent->getMorphClass(), 'subject_id' => $agent->id])
            ->assertCanSeeTableRecords([$adminAgentUpdate])
            ->assertCanNotSeeTableRecords([$aliceLogin, $adminLogin]);
    }
}
