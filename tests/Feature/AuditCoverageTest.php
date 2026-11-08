<?php

namespace Tests\Feature;

use App\Console\Commands\AuditReport;
use App\Events\Approval\ApprovalDecided;
use App\Filament\Resources\Agents\Pages\CreateAgent;
use App\Filament\Resources\Agents\Pages\EditAgent;
use App\Filament\Resources\AiModels\Pages\CreateAiModel;
use App\Filament\Resources\AiModels\Pages\EditAiModel;
use App\Filament\Resources\AiProviders\Pages\CreateAiProvider;
use App\Filament\Resources\AiProviders\Pages\EditAiProvider;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tools\Pages\CreateTool;
use App\Filament\Resources\Tools\Pages\EditTool;
use App\Filament\Resources\UserPreferences\Pages\EditUserPreference;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\WhatsappSessions\Pages\ListWhatsappSessions;
use App\Jobs\DeliverWhatsappOutbound;
use App\Models\Agent;
use App\Models\AiModel;
use App\Models\AiModelCapability;
use App\Models\AiProvider;
use App\Models\AiProviderDriver;
use App\Models\Approval;
use App\Models\AuditAction;
use App\Models\AuditLog;
use App\Models\McpServer;
use App\Models\Memory;
use App\Models\Task;
use App\Models\Tool;
use App\Models\ToolType;
use App\Models\User;
use App\Models\UserPreference;
use App\Models\WhatsappConsent;
use App\Models\WhatsappOutboundMessage;
use App\Models\WhatsappOutboundStatus;
use App\Models\WhatsappSchedule;
use App\Models\WhatsappSession;
use App\Services\ApprovalService;
use App\Services\WhatsappOutboundService;
use App\Support\Settings;
use App\Tools\Contracts\JarvisTool;
use App\Tools\ToolContext;
use App\Tools\ToolExecutor;
use App\Tools\ToolResult;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Concerns\FakesWuzapi;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

/**
 * US-12.3, US-8.3: every write tool execution names the approval and the
 * approver, and every declared audit action is emitted by a real flow.
 */
class AuditCoverageTest extends TestCase
{
    use FakesWuzapi;
    use RefreshDatabase;
    use SeedsLookups;

    private const string FRONTEND_REFERER = 'http://localhost:5173/login';

    private const string WHATSAPP_CONTACT = '5511988887777@s.whatsapp.net';

    private User $admin;

    private User $operator;

    private bool $whatsappReady = false;

    /**
     * @var Collection<int, DeliverWhatsappOutbound>
     */
    private Collection $deliveredJobs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        Filament::setCurrentPanel('admin');

        config(['broadcasting.default' => 'null']);

        Queue::fake();
        Event::fake([ApprovalDecided::class]);

        $this->admin = User::factory()->admin()->create();
        $this->operator = User::factory()->create(['email' => 'operador@example.com', 'password' => 'senha-correta-123']);

        RecordingWriteTool::$runs = 0;

        $this->deliveredJobs = new Collection;
    }

    protected function tearDown(): void
    {
        Queue::releaseUniqueJobLocks();

        parent::tearDown();
    }

    public function test_write_tool_execution_records_approver(): void
    {
        $tool = $this->writeTool();

        $approval = Approval::factory()->for($this->operator)->pending()->create([
            'tool_id' => $tool->id,
            'input' => ['note' => 'enviar'],
        ]);

        app(ApprovalService::class)->approve($approval, $this->operator);

        $result = app(ToolExecutor::class)->execute($tool, ['note' => 'enviar'], ToolContext::forApproval($approval->fresh()));

        $this->assertTrue($result->isCompleted());
        $this->assertSame(1, RecordingWriteTool::$runs);

        $audit = AuditLog::query()
            ->where('audit_action_id', AuditAction::idFor(AuditAction::TOOL_EXECUTED))
            ->sole();

        $this->assertTrue($audit->user->is($this->operator));
        $this->assertTrue($audit->subject->is($result->execution));
        $this->assertSame($approval->id, $audit->properties['approval_id']);
        $this->assertSame($this->operator->id, $audit->properties['approved_by']);
        $this->assertSame('audit.write', $audit->properties['tool']);
        $this->assertArrayNotHasKey('input', $audit->properties);
    }

    public function test_read_only_tool_execution_is_not_audited(): void
    {
        $tool = $this->writeTool(['identifier' => 'audit.read', 'is_write' => false, 'is_sensitive' => false]);

        app(ToolExecutor::class)->execute($tool, ['note' => 'ler'], new ToolContext($this->operator));

        $this->assertFalse(AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::TOOL_EXECUTED))->exists());
    }

    public function test_all_declared_audit_actions_are_emitted_by_some_flow(): void
    {
        $fixtures = $this->fixtures();

        $declared = AuditAction::query()->orderBy('slug')->pluck('slug')->all();
        $covered = array_keys($fixtures);
        sort($covered);

        $this->assertSame($declared, $covered, 'Every declared audit action needs a flow fixture (and vice versa).');

        foreach ($fixtures as $slug => $flow) {
            $before = AuditLog::query()->where('audit_action_id', AuditAction::idFor($slug))->count();

            $flow();

            // API flows switch the default guard to `sanctum`; start each flow clean.
            $this->app['auth']->forgetGuards();
            $this->app['auth']->shouldUse('web');

            $this->assertGreaterThan(
                $before,
                AuditLog::query()->where('audit_action_id', AuditAction::idFor($slug))->count(),
                "The flow for [{$slug}] did not write its audit entry.",
            );
        }
    }

    public function test_audit_report_exports_period_as_csv(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 10));
        app(Settings::class)->update(['brain.messages_per_minute' => 30], $this->admin);

        $this->travelTo(now()->setDate(2026, 10, 20));
        app(Settings::class)->update(['brain.messages_per_minute' => 40], $this->admin);

        $this->travelTo(now()->setDate(2026, 11, 2));
        app(Settings::class)->update(['brain.messages_per_minute' => 50], $this->admin);

        $path = storage_path('framework/testing/audit-report-'.uniqid().'.csv');
        @mkdir(dirname($path), 0755, true);

        $this->artisan('jarvis:audit:report', ['--from' => '2026-10-15', '--to' => '2026-10-31', '--output' => $path])
            ->expectsOutputToContain('1 audit entries written')
            ->assertSuccessful();

        $rows = array_map(str_getcsv(...), array_filter(explode("\n", (string) file_get_contents($path))));
        @unlink($path);

        $this->assertSame(AuditReport::COLUMNS, $rows[0]);
        $this->assertCount(2, $rows);
        $this->assertSame(AuditAction::SETTING_UPDATED, $rows[1][1]);
        $this->assertSame((string) $this->admin->id, $rows[1][2]);
        $this->assertSame($this->admin->email, $rows[1][3]);
        $this->assertSame(40, json_decode($rows[1][7], true)['new']);

        $this->artisan('jarvis:audit:report', ['--from' => '2026-11-01', '--to' => '2026-10-01'])->assertExitCode(2);
    }

    /**
     * One real flow per audit action slug.
     *
     * @return array<string, Closure(): void>
     */
    private function fixtures(): array
    {
        return [
            AuditAction::LOGIN_SUCCEEDED => fn () => $this->postJson('/api/v1/auth/login', [
                'email' => 'operador@example.com',
                'password' => 'senha-correta-123',
            ], ['Referer' => self::FRONTEND_REFERER])->assertOk(),
            AuditAction::LOGIN_FAILED => fn () => $this->postJson('/api/v1/auth/login', [
                'email' => 'operador@example.com',
                'password' => 'senha-errada',
            ], ['Referer' => self::FRONTEND_REFERER])->assertStatus(422),
            AuditAction::LOGOUT => function (): void {
                $this->actingAs($this->operator);
                $this->postJson('/api/v1/auth/logout', [], ['Referer' => self::FRONTEND_REFERER])->assertSuccessful();
            },

            AuditAction::USER_CREATED => fn () => $this->asAdmin(fn () => Livewire::test(CreateUser::class)
                ->fillForm([
                    'name' => 'Nova Operadora',
                    'email' => 'nova@example.com',
                    'password' => 'exactly12chr',
                    'is_admin' => false,
                    'is_active' => true,
                ])
                ->call('create')
                ->assertHasNoFormErrors()),
            AuditAction::USER_UPDATED => fn () => $this->asAdmin(fn () => Livewire::test(EditUser::class, ['record' => $this->operator->getRouteKey()])
                ->fillForm(['name' => 'Renomeado', 'password' => ''])
                ->call('save')
                ->assertHasNoFormErrors()),
            AuditAction::USER_DEACTIVATED => fn () => $this->asAdmin(fn () => Livewire::test(EditUser::class, ['record' => User::factory()->create()->getRouteKey()])
                ->fillForm(['is_active' => false, 'password' => ''])
                ->call('save')
                ->assertHasNoFormErrors()),

            AuditAction::APPROVAL_APPROVED => function (): void {
                $this->actingAs($this->operator);
                $approval = Approval::factory()->for($this->operator)->pending()->create();
                $this->postJson("/api/v1/approvals/{$approval->id}/approve")->assertOk();
            },
            AuditAction::APPROVAL_DENIED => function (): void {
                $this->actingAs($this->operator);
                $approval = Approval::factory()->for($this->operator)->pending()->create();
                $this->postJson("/api/v1/approvals/{$approval->id}/deny", ['reason' => 'não'])->assertOk();
            },
            AuditAction::APPROVAL_EXPIRED => function (): void {
                Approval::factory()->for($this->operator)->pending()->create([
                    'requested_at' => now()->subDays(2),
                    'expires_at' => now()->subDay(),
                ]);
                $this->artisan('jarvis:approvals:expire')->assertSuccessful();
            },
            AuditAction::TOOL_EXECUTED => function (): void {
                $tool = $this->writeTool(['identifier' => 'audit.write.flow']);
                $approval = Approval::factory()->for($this->operator)->pending()->create(['tool_id' => $tool->id]);
                app(ApprovalService::class)->approve($approval, $this->operator);
                app(ToolExecutor::class)->execute($tool, ['note' => 'x'], ToolContext::forApproval($approval->fresh()));
            },

            AuditAction::SETTING_UPDATED => fn () => app(Settings::class)->update(['brain.messages_per_minute' => 42], $this->admin),

            AuditAction::AGENT_CREATED => fn () => $this->asAdmin(fn () => Livewire::test(CreateAgent::class)
                ->fillForm([
                    'name' => 'Agente Auditado',
                    'slug' => 'audit-agent',
                    'description' => 'Agente do teste de auditoria.',
                    'objective' => 'Ser auditado.',
                    'instructions' => 'Instruções de teste.',
                    'ai_model_id' => null,
                    'tools' => [],
                    'max_delegation_depth' => 3,
                    'max_tool_calls' => 20,
                    'timeout_seconds' => 900,
                    'is_default' => false,
                    'is_active' => true,
                ])
                ->call('create')
                ->assertHasNoFormErrors()),
            AuditAction::AGENT_UPDATED => fn () => $this->asAdmin(fn () => Livewire::test(EditAgent::class, ['record' => Agent::factory()->create()->getRouteKey()])
                ->fillForm(['description' => 'Descrição revisada.'])
                ->call('save')
                ->assertHasNoFormErrors()),
            AuditAction::AGENT_DELETED => fn () => $this->asAdmin(fn () => Livewire::test(EditAgent::class, ['record' => Agent::factory()->create()->getRouteKey()])
                ->callAction(DeleteAction::class)),

            AuditAction::PROVIDER_CREATED => fn () => $this->asAdmin(fn () => Livewire::test(CreateAiProvider::class)
                ->fillForm([
                    'name' => 'Auditado',
                    'slug' => 'audited-openai',
                    'ai_provider_driver_id' => AiProviderDriver::idFor(AiProviderDriver::OPENAI),
                    'api_key' => 'sk-audited-key',
                    'base_url' => null,
                    'is_active' => true,
                ])
                ->call('create')
                ->assertHasNoFormErrors()),
            AuditAction::PROVIDER_UPDATED => fn () => $this->asAdmin(fn () => Livewire::test(EditAiProvider::class, ['record' => AiProvider::factory()->create()->getRouteKey()])
                ->fillForm(['name' => 'Provedor renomeado'])
                ->call('save')
                ->assertHasNoFormErrors()),
            AuditAction::PROVIDER_DELETED => fn () => $this->asAdmin(fn () => Livewire::test(EditAiProvider::class, ['record' => AiProvider::factory()->create()->getRouteKey()])
                ->callAction(DeleteAction::class)),

            AuditAction::MODEL_CREATED => fn () => $this->asAdmin(fn () => Livewire::test(CreateAiModel::class)
                ->fillForm([
                    'ai_provider_id' => AiProvider::factory()->create()->id,
                    'name' => 'Modelo auditado',
                    'model_identifier' => 'gpt-audited',
                    'context_window' => 128000,
                    'input_cost_per_million' => 5,
                    'output_cost_per_million' => 15,
                    'capabilities' => [AiModelCapability::idFor(AiModelCapability::TEXT)],
                    'is_default_text' => false,
                    'is_default_embedding' => false,
                    'fallback_order' => null,
                    'is_active' => true,
                ])
                ->call('create')
                ->assertHasNoFormErrors()),
            AuditAction::MODEL_UPDATED => fn () => $this->asAdmin(fn () => Livewire::test(EditAiModel::class, ['record' => AiModel::factory()->create()->getRouteKey()])
                ->fillForm(['name' => 'Modelo renomeado'])
                ->call('save')
                ->assertHasNoFormErrors()),
            AuditAction::MODEL_DELETED => fn () => $this->asAdmin(fn () => Livewire::test(EditAiModel::class, ['record' => AiModel::factory()->create()->getRouteKey()])
                ->callAction(DeleteAction::class)),

            AuditAction::TOOL_CREATED => fn () => $this->asAdmin(fn () => Livewire::test(CreateTool::class)
                ->fillForm([
                    'tool_type_id' => ToolType::idFor(ToolType::INTERNAL),
                    'identifier' => 'internal.audited',
                    'name' => 'Ferramenta auditada',
                    'description' => 'Ferramenta criada pelo teste de auditoria.',
                    'input_schema' => ['type' => 'object', 'properties' => []],
                    'output_schema' => ['type' => 'object', 'properties' => []],
                    'timeout_seconds' => 30,
                    'is_write' => false,
                    'is_sensitive' => false,
                    'is_active' => true,
                ])
                ->call('create')
                ->assertHasNoFormErrors()),
            AuditAction::TOOL_UPDATED => fn () => $this->asAdmin(fn () => Livewire::test(EditTool::class, ['record' => Tool::factory()->rest()->create()->getRouteKey()])
                ->fillForm(['name' => 'Ferramenta renomeada'])
                ->call('save')
                ->assertHasNoFormErrors()),
            AuditAction::TOOL_DELETED => fn () => $this->asAdmin(fn () => Livewire::test(EditTool::class, ['record' => Tool::factory()->internal()->create()->getRouteKey()])
                ->callAction(DeleteAction::class)),

            AuditAction::CONNECTOR_CREATED => function (): void {
                $this->actingAs($this->operator);
                $this->postJson('/api/v1/connectors', ['name' => 'ERP auditado', 'url' => 'https://erp.example.com/mcp'])->assertCreated();
            },
            AuditAction::CONNECTOR_UPDATED => function (): void {
                $this->actingAs($this->operator);
                $connector = McpServer::factory()->for($this->operator)->create();
                $this->patchJson("/api/v1/connectors/{$connector->id}", ['name' => 'Conector renomeado'])->assertOk();
            },
            AuditAction::CONNECTOR_DELETED => function (): void {
                $this->actingAs($this->operator);
                $connector = McpServer::factory()->for($this->operator)->create();
                $this->deleteJson("/api/v1/connectors/{$connector->id}")->assertSuccessful();
            },

            AuditAction::MEMORY_DELETED => function (): void {
                $this->actingAs($this->operator);
                $memory = Memory::factory()->for($this->operator)->confirmed()->create();
                $this->deleteJson("/api/v1/memories/{$memory->id}")->assertOk();
            },
            AuditAction::TASK_CANCELLED => function (): void {
                // The task must exist before the table mounts.
                $task = Task::factory()->running()->for($this->operator)->create();
                $this->asAdmin(fn () => Livewire::test(ListTasks::class)->callTableAction('cancel', $task));
            },
            AuditAction::PREFERENCE_UPDATED => fn () => $this->asAdmin(fn () => Livewire::test(EditUserPreference::class, [
                'record' => UserPreference::factory()->for(User::factory())->create()->getRouteKey(),
            ])
                ->fillForm(['speech_rate' => 1.5])
                ->call('save')
                ->assertHasNoFormErrors()),

            ...$this->whatsappFixtures(),
        ];
    }

    /**
     * CT-41 WhatsApp slugs (RNF-09), each emitted by its real flow: the
     * consent/session API, the admin resource actions, the send tool and its
     * delivery, the guard, the schedule tool, the scheduler and CT-08.
     *
     * @return array<string, Closure(): void>
     */
    private function whatsappFixtures(): array
    {
        return [
            AuditAction::WHATSAPP_CONSENT_GRANTED => function (): void {
                $this->actingAs($this->whatsappUser(consent: false));
                $this->postJson('/api/v1/whatsapp/consent', [
                    'version' => WhatsappConsent::CURRENT_VERSION,
                    'scopes' => WhatsappConsent::SCOPES,
                ])->assertOk();
            },
            AuditAction::WHATSAPP_CONSENT_REVOKED => function (): void {
                $this->actingAs($this->whatsappSession()->user);
                $this->deleteJson('/api/v1/whatsapp/consent')->assertOk();
            },
            AuditAction::WHATSAPP_SESSION_PROVISIONED => function (): void {
                $this->actingAs($this->whatsappUser());
                $this->postJson('/api/v1/whatsapp/session')->assertCreated();
            },
            AuditAction::WHATSAPP_SESSION_REMOVED => function (): void {
                $this->actingAs($this->whatsappSession()->user);
                $this->deleteJson('/api/v1/whatsapp/session')->assertOk();
            },
            AuditAction::WHATSAPP_SESSION_SUSPENDED => fn () => $this->asAdmin(fn () => Livewire::test(ListWhatsappSessions::class)
                ->callTableAction('suspend', $this->whatsappSession())
                ->assertHasNoTableActionErrors()),
            AuditAction::WHATSAPP_SESSION_RESUMED => fn () => $this->asAdmin(fn () => Livewire::test(ListWhatsappSessions::class)
                ->callTableAction('resume', $this->whatsappSession(suspended: true))
                ->assertHasNoTableActionErrors()),
            AuditAction::WHATSAPP_MESSAGE_SENT => function (): void {
                $session = $this->whatsappSession();

                $result = $this->executeApprovedWhatsappTool($session->user, 'whatsapp.send.text', ['to' => self::WHATSAPP_CONTACT, 'text' => 'Olá!']);
                $this->assertTrue($result->isCompleted(), (string) $result->error);

                $this->runWhatsappDeliveries();
            },
            AuditAction::WHATSAPP_SEND_BLOCKED => function (): void {
                $session = $this->whatsappSession();

                $result = $this->executeApprovedWhatsappTool($session->user, 'whatsapp.send.text', ['to' => self::WHATSAPP_CONTACT, 'text' => 'Olá!']);
                $this->assertTrue($result->isCompleted(), (string) $result->error);

                // The kill switch is re-checked at delivery (RF-28): the queued send is blocked.
                app(Settings::class)->set('whatsapp.kill_switch', 1);

                try {
                    $this->runWhatsappDeliveries();
                } finally {
                    app(Settings::class)->set('whatsapp.kill_switch', 0);
                }

                $outbound = WhatsappOutboundMessage::query()->with('status')->findOrFail($result->output['outbound_id']);
                $this->assertSame(WhatsappOutboundStatus::BLOCKED, $outbound->status->slug);
            },
            AuditAction::WHATSAPP_SCHEDULE_CREATED => function (): void {
                $session = $this->whatsappSession();

                $result = $this->executeApprovedWhatsappTool($session->user, 'whatsapp.schedule.create', [
                    'to' => self::WHATSAPP_CONTACT,
                    'kind' => 'text',
                    'text' => 'Lembrete',
                    'run_at' => now()->addDay()->toIso8601String(),
                ]);
                $this->assertTrue($result->isCompleted(), (string) $result->error);
            },
            AuditAction::WHATSAPP_SCHEDULE_EXECUTED => function (): void {
                $session = $this->whatsappSession();
                WhatsappSchedule::factory()->forSession($session)->create([
                    'to_jid' => self::WHATSAPP_CONTACT,
                    'payload' => ['text' => 'Bom dia'],
                    'run_at' => now()->subMinute(),
                    'next_run_at' => now()->subMinute(),
                ]);

                $this->artisan('jarvis:whatsapp:dispatch-schedules')->assertSuccessful();
                $this->runWhatsappDeliveries();
            },
            AuditAction::WHATSAPP_SCHEDULE_CANCELLED => function (): void {
                $session = $this->whatsappSession();
                $schedule = WhatsappSchedule::factory()->forSession($session)->create(['to_jid' => self::WHATSAPP_CONTACT]);

                $this->actingAs($session->user);
                $this->postJson("/api/v1/whatsapp/schedules/{$schedule->id}/cancel")->assertOk();
            },
        ];
    }

    private function whatsappUser(bool $consent = true): User
    {
        $this->fakeWhatsappOnce();

        $user = User::factory()->create();

        if ($consent) {
            WhatsappConsent::factory()->for($user)->create();
        }

        return $user;
    }

    /**
     * A connected, consented session with its Redis delivery locks released
     * (session ids repeat across runs after `migrate:fresh`).
     */
    private function whatsappSession(bool $suspended = false): WhatsappSession
    {
        $this->fakeWhatsappOnce();

        $factory = WhatsappSession::factory()->connected()->withConsent();

        $session = ($suspended ? $factory->suspended() : $factory)->create(['wuzapi_user_id' => 'wuzapi-user-1']);

        (new UniqueLock(Cache::store()))->release(new DeliverWhatsappOutbound($session->id));
        Cache::lock(WhatsappOutboundService::deliveryLockKey($session->id))->forceRelease();
        Cache::lock(WhatsappOutboundService::guardLockKey($session->id))->forceRelease();

        return $session;
    }

    /**
     * The wuzapi fake and the internal tool catalogue are only set up for the
     * WhatsApp flows, so the other fixtures keep their own HTTP behaviour.
     */
    private function fakeWhatsappOnce(): void
    {
        if ($this->whatsappReady) {
            return;
        }

        $this->whatsappReady = true;

        $this->fakeWuzapi();
        Sleep::fake();

        $this->artisan('jarvis:tools:sync-internal')->assertSuccessful();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function executeApprovedWhatsappTool(User $user, string $identifier, array $input): ToolResult
    {
        $tool = Tool::query()->where('identifier', $identifier)->firstOrFail();
        $approval = Approval::factory()->approved()->create(['user_id' => $user->id, 'tool_id' => $tool->id, 'input' => $input]);

        return app(ToolExecutor::class)->execute($tool, $input, ToolContext::forApproval($approval));
    }

    /**
     * Run the pushed delivery jobs as a worker would.
     */
    private function runWhatsappDeliveries(): void
    {
        foreach (Queue::pushed(DeliverWhatsappOutbound::class) as $job) {
            if ($this->deliveredJobs->contains($job)) {
                continue;
            }

            $this->deliveredJobs->push($job);

            (new UniqueLock(Cache::store()))->release($job);

            $job->handle(app(WhatsappOutboundService::class));
        }
    }

    private function asAdmin(Closure $flow): void
    {
        $this->actingAs($this->admin);

        $flow();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function writeTool(array $attributes = []): Tool
    {
        return Tool::factory()->internal()->create([
            'identifier' => 'audit.write',
            'handler_class' => RecordingWriteTool::class,
            'input_schema' => [
                'type' => 'object',
                'properties' => ['note' => ['type' => 'string']],
                'required' => ['note'],
            ],
            'output_schema' => ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]],
            'is_write' => true,
            'is_sensitive' => false,
            ...$attributes,
        ]);
    }
}

class RecordingWriteTool implements JarvisTool
{
    public static int $runs = 0;

    public function identifier(): string
    {
        return 'audit.write';
    }

    public function name(): string
    {
        return 'Escrita auditada';
    }

    public function description(): string
    {
        return 'Ferramenta de escrita usada pelo teste de auditoria.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['note' => ['type' => 'string']], 'required' => ['note']];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]];
    }

    public function handle(array $input, ToolContext $context): array
    {
        self::$runs++;

        return ['ok' => true];
    }
}
