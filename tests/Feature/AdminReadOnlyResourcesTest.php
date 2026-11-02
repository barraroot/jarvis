<?php

namespace Tests\Feature;

use App\Filament\Resources\Agents\Pages\ListAgents;
use App\Filament\Resources\AiProviders\Pages\ListAiProviders;
use App\Filament\Resources\Approvals\ApprovalResource;
use App\Filament\Resources\Approvals\Pages\ListApprovals;
use App\Filament\Resources\Approvals\Pages\ViewApproval;
use App\Filament\Resources\Notifications\NotificationResource;
use App\Filament\Resources\Notifications\Pages\ListNotifications;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\Tools\Pages\ListTools;
use App\Filament\Resources\UserPreferences\Pages\EditUserPreference;
use App\Filament\Resources\UserPreferences\Pages\ListUserPreferences;
use App\Models\Agent;
use App\Models\AiProvider;
use App\Models\Approval;
use App\Models\ApprovalStatus;
use App\Models\AuditAction;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\Task;
use App\Models\TaskLog;
use App\Models\TaskStatus;
use App\Models\TaskStep;
use App\Models\Tool;
use App\Models\User;
use App\Models\UserPreference;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class AdminReadOnlyResourcesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $admin;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        Filament::setCurrentPanel('admin');

        // Task cancellation broadcasts; no Reverb server in tests.
        config(['broadcasting.default' => 'null']);

        $this->admin = User::factory()->admin()->create();
        $this->operator = User::factory()->create(['name' => 'Olívia Operadora']);
        $this->actingAs($this->admin);
    }

    public function test_admin_cannot_decide_other_users_approval(): void
    {
        $approval = Approval::factory()->pending()->for($this->operator)->create();

        // Visible in the admin, read-only.
        $this->get('/admin/approvals')->assertOk()->assertSee('Olívia Operadora');
        $this->get('/admin/approvals/'.$approval->id)->assertOk();

        Livewire::test(ListApprovals::class)
            ->assertCanSeeTableRecords([$approval])
            ->assertTableActionExists('view')
            ->assertTableActionDoesNotExist('approve')
            ->assertTableActionDoesNotExist('deny')
            ->assertTableActionDoesNotExist('edit');

        Livewire::test(ViewApproval::class, ['record' => $approval->getRouteKey()])
            ->assertActionDoesNotExist('approve')
            ->assertActionDoesNotExist('deny');

        $this->assertFalse(ApprovalResource::hasPage('edit'));
        $this->assertFalse(ApprovalResource::canEdit($approval));

        // The API refuses the admin's decision on someone else's approval.
        $this->postJson(route('api.v1.approvals.approve', $approval))->assertForbidden();
        $this->postJson(route('api.v1.approvals.deny', $approval))->assertForbidden();

        $this->assertTrue($approval->fresh()->isPending());
        $this->assertNull($approval->fresh()->decided_by_user_id);
        $this->assertSame(ApprovalStatus::idFor(ApprovalStatus::PENDING), $approval->fresh()->approval_status_id);

        // Notifications are read-only too.
        $notification = Notification::factory()->for($this->operator)->create(['title' => 'Aviso para Olívia']);

        $this->get('/admin/notifications')->assertOk()->assertSee('Aviso para Olívia');
        Livewire::test(ListNotifications::class)->assertCanSeeTableRecords([$notification]);
        $this->assertFalse(NotificationResource::canEdit($notification));
        $this->assertFalse(NotificationResource::canDelete($notification));
    }

    public function test_admin_can_inspect_and_cancel_any_task_with_audit(): void
    {
        $task = Task::factory()->running()->for($this->operator)->create(['title' => 'Relatório longo']);
        $step = TaskStep::factory()->for($task)->completed()->create(['name' => 'Coletar dados']);
        TaskLog::factory()->for($task)->create(['task_step_id' => $step->id, 'message' => 'Coleta iniciada']);

        $this->get('/admin/tasks')->assertOk()->assertSee('Relatório longo');
        $this->get('/admin/tasks/'.$task->id)->assertOk()->assertSee('Relatório longo');
        $this->assertFalse(TaskResource::hasPage('edit'));

        Livewire::test(ViewTask::class, ['record' => $task->getRouteKey()])
            ->assertActionVisible('cancel');

        Livewire::test(ListTasks::class)
            ->callTableAction('cancel', $task);

        $this->assertSame(TaskStatus::idFor(TaskStatus::CANCELLED), $task->fresh()->task_status_id);

        $this->assertDatabaseHas('audit_logs', [
            'audit_action_id' => AuditAction::idFor(AuditAction::TASK_CANCELLED),
            'user_id' => $this->admin->id,
            'subject_type' => $task->getMorphClass(),
            'subject_id' => $task->id,
        ]);

        // Terminal tasks cannot be cancelled again.
        Livewire::test(ListTasks::class)->assertTableActionHidden('cancel', $task->fresh());
    }

    public function test_admin_edits_voice_settings_and_resets_wake_word_consent(): void
    {
        $preference = UserPreference::factory()->for($this->operator)->wakeWordEnabled()->create();

        $this->get('/admin/user-preferences')->assertOk()->assertSee('Olívia Operadora');

        Livewire::test(EditUserPreference::class, ['record' => $preference->getRouteKey()])
            ->fillForm(['speech_rate' => 3])
            ->call('save')
            ->assertHasFormErrors(['speech_rate' => 'max']);

        Livewire::test(EditUserPreference::class, ['record' => $preference->getRouteKey()])
            ->fillForm(['speech_rate' => 1.5, 'voice_name' => 'Luciana'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('1.50', $preference->fresh()->speech_rate);
        $this->assertSame('Luciana', $preference->fresh()->voice_name);

        Livewire::test(ListUserPreferences::class)
            ->callTableAction('resetWakeWordConsent', $preference);

        $preference->refresh();
        $this->assertFalse($preference->wake_word_enabled);
        $this->assertNull($preference->wake_word_consented_at);

        $this->assertSame(2, AuditLog::query()
            ->where('audit_action_id', AuditAction::idFor(AuditAction::PREFERENCE_UPDATED))
            ->where('subject_id', $preference->id)
            ->count());
    }

    public function test_bulk_toggle_deactivates_agents(): void
    {
        $default = Agent::factory()->default()->create();
        $first = Agent::factory()->create();
        $second = Agent::factory()->create();
        $inactive = Agent::factory()->inactive()->create();

        Livewire::test(ListAgents::class)
            ->selectTableRecords([$default->getKey(), $first->getKey(), $second->getKey()])
            ->callAction(TestAction::make('deactivate')->table()->bulk());

        $this->assertTrue($default->fresh()->is_active, 'The default agent must stay active.');
        $this->assertFalse($first->fresh()->is_active);
        $this->assertFalse($second->fresh()->is_active);

        $this->assertSame(2, AuditLog::query()
            ->where('audit_action_id', AuditAction::idFor(AuditAction::AGENT_UPDATED))
            ->where('user_id', $this->admin->id)
            ->count());

        Livewire::test(ListAgents::class)
            ->selectTableRecords([$first->getKey(), $inactive->getKey()])
            ->callAction(TestAction::make('activate')->table()->bulk());

        $this->assertTrue($first->fresh()->is_active);
        $this->assertTrue($inactive->fresh()->is_active);

        // Providers and tools expose the same toggles.
        $provider = AiProvider::factory()->create(['is_active' => true]);
        Livewire::test(ListAiProviders::class)
            ->selectTableRecords([$provider->getKey()])
            ->callAction(TestAction::make('deactivate')->table()->bulk());
        $this->assertFalse($provider->fresh()->is_active);

        $tool = Tool::factory()->create(['is_active' => true]);
        Livewire::test(ListTools::class)
            ->selectTableRecords([$tool->getKey()])
            ->callAction(TestAction::make('deactivate')->table()->bulk());
        $this->assertFalse($tool->fresh()->is_active);
    }
}
