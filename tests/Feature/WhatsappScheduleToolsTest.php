<?php

namespace Tests\Feature;

use App\Exceptions\WhatsappOperationException;
use App\Models\Approval;
use App\Models\AuditAction;
use App\Models\AuditLog;
use App\Models\ExecutionStatus;
use App\Models\Tool;
use App\Models\ToolExecution;
use App\Models\User;
use App\Models\WhatsappConsent;
use App\Models\WhatsappSchedule;
use App\Models\WhatsappScheduleStatus;
use App\Models\WhatsappSession;
use App\Services\ApprovalService;
use App\Support\HostResolver;
use App\Support\Settings;
use App\Tools\Internal\WhatsappScheduleCancelTool;
use App\Tools\Internal\WhatsappScheduleCreateTool;
use App\Tools\Internal\WhatsappScheduleListTool;
use App\Tools\ToolContext;
use App\Tools\ToolExecutor;
use App\Tools\ToolResult;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesWuzapi;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class WhatsappScheduleToolsTest extends TestCase
{
    use FakesWuzapi;
    use RefreshDatabase;
    use SeedsLookups;

    private const string CONTACT = '5511988887777@s.whatsapp.net';

    private User $user;

    private WhatsappSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();
        $this->fakeWuzapi();

        // Friday 2026-11-06 10:00 in São Paulo.
        $this->travelTo(CarbonImmutable::parse('2026-11-06 10:00:00', WhatsappSchedule::TIMEZONE)->utc());

        $this->artisan('jarvis:tools:sync-internal')->assertSuccessful();

        $this->session = WhatsappSession::factory()->connected()->withConsent()->create();
        $this->user = $this->session->user;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function executeApproved(string $identifier, array $input, ?User $user = null): ToolResult
    {
        $user ??= $this->user;
        $tool = Tool::query()->where('identifier', $identifier)->firstOrFail();
        $approval = $tool->requiresApproval()
            ? Approval::factory()->approved()->create(['user_id' => $user->id, 'tool_id' => $tool->id, 'input' => $input])
            : null;

        return app(ToolExecutor::class)->execute($tool, $input, new ToolContext($user, approval: $approval));
    }

    private function assertFailedWith(ToolResult $result, string $needle): void
    {
        $this->assertSame(ExecutionStatus::FAILED, $result->status);
        $this->assertStringContainsString($needle, (string) $result->error);
    }

    private function audits(string $action): int
    {
        return AuditLog::query()->where('audit_action_id', AuditAction::idFor($action))->count();
    }

    public function test_catalogue_registers_the_schedule_tools_with_ct30_flags_and_scopes(): void
    {
        $expected = [
            'whatsapp.schedule.create' => [WhatsappScheduleCreateTool::class, true],
            'whatsapp.schedule.list' => [WhatsappScheduleListTool::class, false],
            'whatsapp.schedule.cancel' => [WhatsappScheduleCancelTool::class, true],
        ];

        foreach ($expected as $identifier => [$class, $isWrite]) {
            $tool = Tool::query()->where('identifier', $identifier)->firstOrFail();

            $this->assertSame($class, $tool->handler_class);
            $this->assertSame($isWrite, $tool->is_write, $identifier);
            $this->assertFalse($tool->is_sensitive, $identifier);
            $this->assertFalse($tool->input_schema['additionalProperties'], $identifier);
            $this->assertSame(['envio'], app($class)->requiredScopes(['kind' => 'text']), $identifier);
        }

        $create = app(WhatsappScheduleCreateTool::class);
        $this->assertSame(['envio', 'midia'], $create->requiredScopes(['kind' => 'image']));
        $this->assertTrue($create->writesToWhatsapp());
        $this->assertFalse(app(WhatsappScheduleCancelTool::class)->writesToWhatsapp());
    }

    public function test_approved_creation_persists_one_active_schedule_with_a_future_next_run_and_one_audit(): void
    {
        $tool = Tool::query()->where('identifier', 'whatsapp.schedule.create')->firstOrFail();
        $input = ['to' => '5511988887777', 'kind' => 'text', 'text' => 'Bom dia!', 'recurrence' => '0 9 * * 1-5'];

        $pending = app(ToolExecutor::class)->execute($tool, $input, new ToolContext($this->user));
        $this->assertSame(ToolResult::PENDING_APPROVAL, $pending->status);
        $this->assertSame(0, WhatsappSchedule::query()->count());

        app(ApprovalService::class)->approve($pending->approval, $this->user);

        $this->assertSame(1, ToolExecution::query()->where('approval_id', $pending->approval->id)->count());

        $schedule = WhatsappSchedule::query()->with('status')->sole();
        $this->assertSame(WhatsappScheduleStatus::ACTIVE, $schedule->statusSlug());
        $this->assertSame($this->user->id, $schedule->user_id);
        $this->assertSame($this->session->id, $schedule->whatsapp_session_id);
        $this->assertSame($pending->approval->id, $schedule->approval_id);
        $this->assertSame(self::CONTACT, $schedule->to_jid);
        $this->assertSame(['text' => 'Bom dia!'], $schedule->payload);
        $this->assertTrue($schedule->next_run_at->isFuture());
        // Next weekday 09:00 in São Paulo: Monday 2026-11-09.
        $this->assertTrue($schedule->next_run_at->equalTo(CarbonImmutable::parse('2026-11-09 09:00:00', WhatsappSchedule::TIMEZONE)));
        $this->assertTrue($schedule->approval_expires_at->equalTo(now()->addDays(90)));
        $this->assertSame(1, $this->audits(AuditAction::WHATSAPP_SCHEDULE_CREATED));
        Http::assertNothingSent();
    }

    public function test_one_off_creation_returns_the_schedule(): void
    {
        $result = $this->executeApproved('whatsapp.schedule.create', [
            'to' => self::CONTACT, 'kind' => 'location', 'latitude' => -23.55, 'longitude' => -46.63, 'run_at' => '2026-11-10T08:30:00',
        ]);

        $this->assertSame(ExecutionStatus::COMPLETED, $result->status, (string) $result->error);
        $this->assertSame(WhatsappScheduleStatus::ACTIVE, $result->output['status']);
        $this->assertNull($result->output['recurrence']);
        $this->assertSame('America/Sao_Paulo', $result->output['timezone']);
        $this->assertTrue(CarbonImmutable::parse($result->output['next_run_at'])->equalTo(CarbonImmutable::parse('2026-11-10 08:30:00', WhatsappSchedule::TIMEZONE)));
        $this->assertSame(['latitude' => -23.55, 'longitude' => -46.63], WhatsappSchedule::query()->sole()->payload);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidDefinitions(): array
    {
        return [
            'every 30 minutes' => [['recurrence' => '*/30 * * * *']],
            'past date' => [['run_at' => '2026-11-05T10:00:00']],
            'one-off beyond 90 days' => [['run_at' => '2027-02-05T10:00:00']],
            'both run_at and recurrence' => [['run_at' => '2026-11-10T10:00:00', 'recurrence' => '0 9 * * *']],
            'six-field cron' => [['recurrence' => '0 0 9 * * *']],
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    #[DataProvider('invalidDefinitions')]
    public function test_invalid_definitions_fail_with_tool_input_invalid(array $definition): void
    {
        $result = $this->executeApproved('whatsapp.schedule.create', ['to' => self::CONTACT, 'kind' => 'text', 'text' => 'Oi', ...$definition]);

        $this->assertFailedWith($result, 'Invalid input for tool [whatsapp.schedule.create]');
        $this->assertSame(0, WhatsappSchedule::query()->count());
        $this->assertSame(0, $this->audits(AuditAction::WHATSAPP_SCHEDULE_CREATED));
    }

    public function test_content_must_match_the_kind(): void
    {
        $missing = $this->executeApproved('whatsapp.schedule.create', ['to' => self::CONTACT, 'kind' => 'text', 'recurrence' => '0 9 * * *']);
        $this->assertFailedWith($missing, 'text is required for kind text');

        $foreign = $this->executeApproved('whatsapp.schedule.create', ['to' => self::CONTACT, 'kind' => 'text', 'text' => 'Oi', 'url' => 'https://cdn.example.com/x.png', 'recurrence' => '0 9 * * *']);
        $this->assertFailedWith($foreign, 'url is not allowed for kind text');

        $this->assertSame(0, WhatsappSchedule::query()->count());
    }

    public function test_creation_with_the_kill_switch_active_fails(): void
    {
        app(Settings::class)->set('whatsapp.kill_switch', 1);

        $result = $this->executeApproved('whatsapp.schedule.create', ['to' => self::CONTACT, 'kind' => 'text', 'text' => 'Oi', 'recurrence' => '0 9 * * *']);

        $this->assertFailedWith($result, WhatsappOperationException::KILL_SWITCH_ACTIVE);
        $this->assertSame(0, WhatsappSchedule::query()->count());
    }

    public function test_media_schedule_requires_the_media_scope_and_a_valid_https_url(): void
    {
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                return $host === 'cdn.example.com' ? ['93.184.216.34'] : [];
            }
        });
        Http::fake(['https://cdn.example.com/x.png' => Http::response("\x89PNG\r\n\x1a\nfake", 200, ['Content-Type' => 'image/png'])]);

        $input = ['to' => self::CONTACT, 'kind' => 'image', 'url' => 'https://cdn.example.com/x.png', 'caption' => 'Foto', 'recurrence' => '0 9 * * *'];

        $insecure = $this->executeApproved('whatsapp.schedule.create', [...$input, 'url' => 'http://cdn.example.com/x.png']);
        $this->assertFailedWith($insecure, WhatsappOperationException::MEDIA_INVALID);
        $this->assertSame(0, WhatsappSchedule::query()->count());

        $created = $this->executeApproved('whatsapp.schedule.create', $input);
        $this->assertSame(ExecutionStatus::COMPLETED, $created->status, (string) $created->error);
        $this->assertSame(['url' => 'https://cdn.example.com/x.png', 'caption' => 'Foto'], WhatsappSchedule::query()->sole()->payload);

        WhatsappConsent::query()->where('user_id', $this->user->id)->update(['scopes' => json_encode(['leitura', 'envio'])]);

        $withoutMedia = $this->executeApproved('whatsapp.schedule.create', $input);
        $this->assertFailedWith($withoutMedia, WhatsappOperationException::CONSENT_REQUIRED);
        $this->assertStringContainsString('midia', (string) $withoutMedia->error);
        $this->assertSame(1, WhatsappSchedule::query()->count());
    }

    public function test_list_returns_only_the_callers_schedules_without_content(): void
    {
        $active = WhatsappSchedule::factory()->forSession($this->session)->recurring()->create(['next_run_at' => now()->addHour()]);
        $cancelled = WhatsappSchedule::factory()->forSession($this->session)->cancelled()->create();
        $other = WhatsappSchedule::factory()->forSession(WhatsappSession::factory()->connected()->withConsent()->create())->create();

        $result = $this->executeApproved('whatsapp.schedule.list', []);

        $this->assertSame(ExecutionStatus::COMPLETED, $result->status, (string) $result->error);
        $this->assertSame([$active->id, $cancelled->id], array_column($result->output['schedules'], 'id'));
        $this->assertArrayNotHasKey('payload', $result->output['schedules'][0]);
        $this->assertNotContains($other->id, array_column($result->output['schedules'], 'id'));

        $filtered = $this->executeApproved('whatsapp.schedule.list', ['status' => WhatsappScheduleStatus::CANCELLED]);
        $this->assertSame([$cancelled->id], array_column($filtered->output['schedules'], 'id'));
        Http::assertNothingSent();
    }

    public function test_cancel_marks_the_schedule_cancelled_and_audits_it(): void
    {
        $schedule = WhatsappSchedule::factory()->forSession($this->session)->recurring()->create();

        $result = $this->executeApproved('whatsapp.schedule.cancel', ['schedule_id' => $schedule->id]);

        $this->assertSame(ExecutionStatus::COMPLETED, $result->status, (string) $result->error);
        $this->assertSame(WhatsappScheduleStatus::CANCELLED, $result->output['status']);
        $this->assertSame(WhatsappScheduleStatus::CANCELLED, $schedule->fresh()->load('status')->statusSlug());
        $this->assertNull($schedule->fresh()->next_run_at);
        $this->assertSame(1, $this->audits(AuditAction::WHATSAPP_SCHEDULE_CANCELLED));
    }

    public function test_cancel_works_with_the_kill_switch_active(): void
    {
        app(Settings::class)->set('whatsapp.kill_switch', 1);
        $schedule = WhatsappSchedule::factory()->forSession($this->session)->create();

        $result = $this->executeApproved('whatsapp.schedule.cancel', ['schedule_id' => $schedule->id]);

        $this->assertSame(ExecutionStatus::COMPLETED, $result->status, (string) $result->error);
    }

    public function test_cancelling_another_users_schedule_fails_like_a_missing_one(): void
    {
        $other = WhatsappSchedule::factory()->forSession(WhatsappSession::factory()->connected()->withConsent()->create())->create();

        $foreign = $this->executeApproved('whatsapp.schedule.cancel', ['schedule_id' => $other->id]);
        $missing = $this->executeApproved('whatsapp.schedule.cancel', ['schedule_id' => $other->id + 1000]);

        $this->assertSame(ExecutionStatus::FAILED, $foreign->status);
        $this->assertSame(
            str_replace((string) $other->id, '', (string) $foreign->error),
            str_replace((string) ($other->id + 1000), '', (string) $missing->error),
        );
        $this->assertSame(WhatsappScheduleStatus::ACTIVE, $other->fresh()->load('status')->statusSlug());
        $this->assertSame(0, $this->audits(AuditAction::WHATSAPP_SCHEDULE_CANCELLED));
    }

    public function test_cancelling_a_completed_schedule_fails_with_the_state_message(): void
    {
        $schedule = WhatsappSchedule::factory()->forSession($this->session)->completed()->create();

        $this->assertFailedWith(
            $this->executeApproved('whatsapp.schedule.cancel', ['schedule_id' => $schedule->id]),
            __('whatsapp.schedules.not_cancellable'),
        );
    }
}
