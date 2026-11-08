<?php

namespace Tests\Feature;

use App\Exceptions\WhatsappOperationException;
use App\Jobs\DeliverWhatsappOutbound;
use App\Models\Approval;
use App\Models\AuditAction;
use App\Models\AuditLog;
use App\Models\ConnectorStatus;
use App\Models\ExecutionStatus;
use App\Models\Tool;
use App\Models\User;
use App\Models\WhatsappConsent;
use App\Models\WhatsappOutboundMessage;
use App\Models\WhatsappOutboundRecipient;
use App\Models\WhatsappOutboundStatus;
use App\Models\WhatsappSession;
use App\Services\WhatsappGroupService;
use App\Services\WhatsappOutboundService;
use App\Services\WhatsappSendGuard;
use App\Support\HostResolver;
use App\Support\Settings;
use App\Tools\Exceptions\ToolInputInvalidException;
use App\Tools\Internal\WhatsappGroupsCreateTool;
use App\Tools\Internal\WhatsappGroupsLeaveTool;
use App\Tools\Internal\WhatsappGroupsParticipantsTool;
use App\Tools\Internal\WhatsappGroupsUpdateTool;
use App\Tools\Internal\WhatsappMessagesMarkReadTool;
use App\Tools\ToolContext;
use App\Tools\ToolExecutor;
use App\Tools\ToolResult;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesWuzapi;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class WhatsappGroupToolsTest extends TestCase
{
    use FakesWuzapi;
    use RefreshDatabase;
    use SeedsLookups;

    private const string CONTACT = '5511988887777@s.whatsapp.net';

    private const string GROUP = '120363000000000000@g.us';

    /**
     * Non-contacts: absent from the fake address book and with no received message.
     *
     * @var list<string>
     */
    private const array STRANGERS = ['5511900000001@s.whatsapp.net', '5511900000002@s.whatsapp.net', '5511900000003@s.whatsapp.net'];

    /**
     * CT-30 group tools: identifier → [class, required scopes, valid input].
     *
     * @var array<string, array{class-string, list<string>, array<string, mixed>}>
     */
    private const array TOOLS = [
        'whatsapp.groups.create' => [WhatsappGroupsCreateTool::class, ['grupos'], ['name' => 'Projeto', 'participants' => [self::CONTACT]]],
        'whatsapp.groups.update' => [WhatsappGroupsUpdateTool::class, ['grupos'], ['group_jid' => self::GROUP, 'name' => 'Novo nome']],
        'whatsapp.groups.participants' => [WhatsappGroupsParticipantsTool::class, ['grupos'], ['group_jid' => self::GROUP, 'action' => 'remove', 'participants' => [self::CONTACT]]],
        'whatsapp.groups.leave' => [WhatsappGroupsLeaveTool::class, ['grupos'], ['group_jid' => self::GROUP]],
        'whatsapp.messages.mark_read' => [WhatsappMessagesMarkReadTool::class, ['leitura'], ['chat_jid' => self::CONTACT, 'message_ids' => ['3EB0AAA', '3EB0BBB']]],
    ];

    private User $user;

    private WhatsappSession $session;

    /**
     * When true, `POST /group/create` answers 500.
     */
    private bool $groupCreateFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();
        $this->fakeWuzapi([
            'POST /group/create' => fn (): mixed => $this->groupCreateFails
                ? Http::response(['code' => 500, 'error' => 'group create failed', 'success' => false], 500)
                : Http::response(['code' => 200, 'data' => ['JID' => self::GROUP, 'Name' => 'Projeto'], 'success' => true]),
        ]);

        Queue::fake();

        $this->artisan('jarvis:tools:sync-internal')->assertSuccessful();

        $this->session = WhatsappSession::factory()->connected()->withConsent()->create();
        $this->user = $this->session->user;

        (new UniqueLock(Cache::store()))->release(new DeliverWhatsappOutbound($this->session->id));
        Cache::lock(WhatsappOutboundService::guardLockKey($this->session->id))->forceRelease();
    }

    protected function tearDown(): void
    {
        Queue::releaseUniqueJobLocks();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function executeApproved(string $identifier, array $input): ToolResult
    {
        $tool = Tool::query()->where('identifier', $identifier)->firstOrFail();
        $approval = Approval::factory()->approved()->create(['user_id' => $this->user->id, 'tool_id' => $tool->id, 'input' => $input]);

        return app(ToolExecutor::class)->execute($tool, $input, new ToolContext($this->user, approval: $approval));
    }

    private function assertFailedWith(ToolResult $result, string $code): void
    {
        $this->assertSame(ExecutionStatus::FAILED, $result->status);
        $this->assertStringContainsString($code, (string) $result->error);
    }

    private function unitsToday(): int
    {
        return app(WhatsappSendGuard::class)->unitsToday($this->session);
    }

    private function blockedAudits(): int
    {
        return AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::WHATSAPP_SEND_BLOCKED))->count();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function toolIdentifiers(): array
    {
        return array_combine(array_keys(self::TOOLS), array_map(fn (string $identifier): array => [$identifier], array_keys(self::TOOLS)));
    }

    public function test_catalogue_registers_the_group_and_mark_read_tools_with_ct30_flags(): void
    {
        foreach (self::TOOLS as $identifier => [$class, $scopes]) {
            $tool = Tool::query()->where('identifier', $identifier)->firstOrFail();
            $handler = app($class);

            $this->assertSame($class, $tool->handler_class);
            $this->assertTrue($tool->is_write, $identifier);
            $this->assertFalse($tool->is_sensitive, $identifier);
            $this->assertFalse($tool->input_schema['additionalProperties'], $identifier);
            $this->assertSame($scopes, $handler->requiredScopes([]), $identifier);
            $this->assertTrue($handler->writesToWhatsapp(), $identifier);
            $this->assertTrue($handler->callsUpstream(), $identifier);
        }
    }

    public function test_without_approval_a_group_tool_only_opens_a_pending_approval(): void
    {
        $tool = Tool::query()->where('identifier', 'whatsapp.groups.create')->firstOrFail();

        $result = app(ToolExecutor::class)->execute($tool, self::TOOLS['whatsapp.groups.create'][2], new ToolContext($this->user));

        $this->assertSame(ToolResult::PENDING_APPROVAL, $result->status);
        $this->assertTrue($result->approval->isPending());
        Http::assertNothingSent();
    }

    public function test_group_with_three_non_contacts_consumes_three_daily_units_and_three_non_contacts_but_no_per_minute(): void
    {
        $result = $this->executeApproved('whatsapp.groups.create', ['name' => 'Projeto', 'participants' => self::STRANGERS]);

        $this->assertSame(ExecutionStatus::COMPLETED, $result->status, (string) $result->error);
        $this->assertSame(self::GROUP, $result->output['jid']);
        $this->assertSame(self::STRANGERS, $result->output['participants']);
        $this->assertWuzapiCalled('POST', '/group/create');

        $outbound = WhatsappOutboundMessage::query()->with('status')->sole();
        $this->assertSame('group_create', $outbound->kind);
        $this->assertSame(WhatsappOutboundStatus::SENT, $outbound->status->slug);
        $this->assertSame(self::GROUP, $outbound->to_jid);
        $this->assertSame($result->execution->approval_id, $outbound->approval_id);

        $this->assertSame(3, $this->unitsToday());
        $this->assertSame(3, WhatsappOutboundRecipient::query()->where('is_non_contact', true)->count());

        // Per-minute limit at its minimum (2): two messages still go through.
        app(Settings::class)->set('whatsapp.max_sends_per_minute', 2);
        $outboundService = app(WhatsappOutboundService::class);

        foreach ([1, 2] as $attempt) {
            $this->assertSame('queued', $outboundService->enqueue($this->user, 'text', self::CONTACT, ['text' => "Oi {$attempt}"])['status']);
        }
    }

    public function test_exceeding_the_daily_cap_rejects_the_whole_group_creation_without_http(): void
    {
        app(Settings::class)->set('whatsapp.daily_send_cap', 50);

        foreach (range(1, 48) as $index) {
            $sent = WhatsappOutboundMessage::factory()->sent()->create(['user_id' => $this->user->id, 'whatsapp_session_id' => $this->session->id]);
            WhatsappOutboundRecipient::query()->create([
                'whatsapp_outbound_message_id' => $sent->id,
                'whatsapp_session_id' => $this->session->id,
                'jid' => $sent->to_jid,
                'is_non_contact' => false,
                'created_at' => now(),
            ]);
        }

        $result = $this->executeApproved('whatsapp.groups.create', ['name' => 'Projeto', 'participants' => self::STRANGERS]);

        $this->assertFailedWith($result, WhatsappOperationException::DAILY_CAP_REACHED);
        $this->assertSame(48, $this->unitsToday());
        $this->assertSame(0, WhatsappOutboundMessage::query()->where('kind', 'group_create')->count());
        $this->assertSame(1, $this->blockedAudits());
        Http::assertNothingSent();
    }

    public function test_exceeding_the_non_contact_limit_rejects_the_whole_addition(): void
    {
        app(Settings::class)->set('whatsapp.max_non_contact_recipients', 2);

        $result = $this->executeApproved('whatsapp.groups.participants', ['group_jid' => self::GROUP, 'action' => 'add', 'participants' => self::STRANGERS]);

        $this->assertFailedWith($result, WhatsappOperationException::MASS_SEND_BLOCKED);
        $this->assertSame(0, WhatsappOutboundMessage::query()->count());
        $this->assertNoWuzapiSend();
    }

    public function test_adding_participants_counts_them_and_other_actions_do_not(): void
    {
        $added = $this->executeApproved('whatsapp.groups.participants', ['group_jid' => self::GROUP, 'action' => 'add', 'participants' => ['+5511900000001', self::CONTACT]]);

        $this->assertSame(ExecutionStatus::COMPLETED, $added->status, (string) $added->error);
        $this->assertSame([self::STRANGERS[0], self::CONTACT], $added->output['participants']);
        $this->assertSame(2, $this->unitsToday());
        $this->assertSame(1, WhatsappOutboundRecipient::query()->where('is_non_contact', true)->count());
        $this->assertSame('group_participants', WhatsappOutboundMessage::query()->sole()->kind);

        foreach (['remove', 'promote', 'demote'] as $action) {
            $result = $this->executeApproved('whatsapp.groups.participants', ['group_jid' => self::GROUP, 'action' => $action, 'participants' => [self::CONTACT]]);
            $this->assertSame(ExecutionStatus::COMPLETED, $result->status, (string) $result->error);
        }

        $this->assertSame(1, WhatsappOutboundMessage::query()->count());
        $this->assertSame(2, $this->unitsToday());
        $this->assertWuzapiCalled('POST', '/group/updateparticipants', 4);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/group/updateparticipants')
            && $request['Action'] === 'demote' && $request['Phone'] === [self::CONTACT] && $request['GroupJID'] === self::GROUP);
    }

    public function test_upstream_failure_records_a_failed_row_that_does_not_count(): void
    {
        $this->groupCreateFails = true;

        $result = $this->executeApproved('whatsapp.groups.create', ['name' => 'Projeto', 'participants' => self::STRANGERS]);

        $this->assertFailedWith($result, WhatsappOperationException::UPSTREAM_ERROR);

        $outbound = WhatsappOutboundMessage::query()->with('status')->sole();
        $this->assertSame(WhatsappOutboundStatus::FAILED, $outbound->status->slug);
        $this->assertSame(WhatsappOperationException::UPSTREAM_ERROR, $outbound->reason);
        $this->assertSame(0, $this->unitsToday());
    }

    #[DataProvider('toolIdentifiers')]
    public function test_kill_switch_blocks_every_group_mutation_and_mark_read(string $identifier): void
    {
        app(Settings::class)->set('whatsapp.kill_switch', 1);

        $this->assertFailedWith($this->executeApproved($identifier, self::TOOLS[$identifier][2]), WhatsappOperationException::KILL_SWITCH_ACTIVE);
        Http::assertNothingSent();
    }

    #[DataProvider('toolIdentifiers')]
    public function test_suspended_session_blocks_every_group_mutation_and_mark_read(string $identifier): void
    {
        $this->session->forceFill(['suspended_at' => now()])->save();

        $this->assertFailedWith($this->executeApproved($identifier, self::TOOLS[$identifier][2]), WhatsappOperationException::SESSION_SUSPENDED);
        Http::assertNothingSent();
    }

    #[DataProvider('toolIdentifiers')]
    public function test_missing_scope_fails_with_consent_required(string $identifier): void
    {
        [, $scopes, $input] = self::TOOLS[$identifier];
        WhatsappConsent::query()->where('user_id', $this->user->id)
            ->update(['scopes' => json_encode(array_values(array_diff(WhatsappConsent::SCOPES, $scopes)))]);

        $result = $this->executeApproved($identifier, $input);

        $this->assertFailedWith($result, WhatsappOperationException::CONSENT_REQUIRED);
        $this->assertStringContainsString($scopes[0], (string) $result->error);
        Http::assertNothingSent();
    }

    public function test_disconnected_session_fails_mark_read_with_not_connected(): void
    {
        $this->session->update(['connector_status_id' => ConnectorStatus::idFor(ConnectorStatus::DISCONNECTED)]);

        $this->assertFailedWith($this->executeApproved('whatsapp.messages.mark_read', self::TOOLS['whatsapp.messages.mark_read'][2]), WhatsappOperationException::NOT_CONNECTED);
        Http::assertNothingSent();
    }

    public function test_mark_read_marks_the_given_messages(): void
    {
        $result = $this->executeApproved('whatsapp.messages.mark_read', ['chat_jid' => self::GROUP, 'message_ids' => ['3EB0AAA', '3EB0AAA', '3EB0BBB'], 'sender_jid' => self::CONTACT]);

        $this->assertSame(ExecutionStatus::COMPLETED, $result->status, (string) $result->error);
        $this->assertSame(['chat_jid' => self::GROUP, 'marked' => 2], $result->output);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/chat/markread')
            && $request['Id'] === ['3EB0AAA', '3EB0BBB'] && $request['Chat'] === self::GROUP && $request['Sender'] === self::CONTACT);
    }

    public function test_update_applies_each_change_including_a_fetched_photo(): void
    {
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                return $host === 'cdn.example.com' ? ['93.184.216.34'] : [];
            }
        });
        Http::fake(['https://cdn.example.com/g.png' => Http::response("\x89PNG\r\n\x1a\nfake", 200, ['Content-Type' => 'image/png'])]);

        $result = $this->executeApproved('whatsapp.groups.update', [
            'group_jid' => self::GROUP,
            'name' => 'Novo nome',
            'topic' => 'Nova descrição',
            'photo_url' => 'https://cdn.example.com/g.png',
            'announce' => true,
            'locked' => false,
        ]);

        $this->assertSame(ExecutionStatus::COMPLETED, $result->status, (string) $result->error);
        $this->assertSame(['name', 'topic', 'photo', 'announce', 'locked'], $result->output['updated']);

        foreach (['/group/name', '/group/topic', '/group/photo', '/group/announce', '/group/locked'] as $path) {
            $this->assertWuzapiCalled('POST', $path);
        }

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/group/photo')
            && str_starts_with((string) $request['Image'], 'data:image/png;base64,'));
        $this->assertSame(0, WhatsappOutboundMessage::query()->count());
    }

    public function test_update_without_any_change_is_rejected(): void
    {
        $result = $this->executeApproved('whatsapp.groups.update', ['group_jid' => self::GROUP]);

        $this->assertSame(ExecutionStatus::FAILED, $result->status);
        $this->assertStringContainsString('at least one of', (string) $result->error);
        Http::assertNothingSent();
    }

    public function test_leave_leaves_the_group(): void
    {
        $result = $this->executeApproved('whatsapp.groups.leave', ['group_jid' => self::GROUP]);

        $this->assertSame(['group_jid' => self::GROUP, 'left' => true], $result->output);
        $this->assertWuzapiCalled('POST', '/group/leave');
    }

    public function test_service_rechecks_the_kill_switch_and_audits_the_block(): void
    {
        app(Settings::class)->set('whatsapp.kill_switch', 1);

        $this->assertThrows(
            fn (): mixed => app(WhatsappGroupService::class)->leave($this->session, self::GROUP),
            WhatsappOperationException::class,
            WhatsappOperationException::KILL_SWITCH_ACTIVE,
        );

        $this->assertSame(1, $this->blockedAudits());
        Http::assertNothingSent();
    }

    public function test_participant_list_with_a_group_jid_is_rejected_by_the_schema(): void
    {
        $this->assertThrows(
            fn (): mixed => $this->executeApproved('whatsapp.groups.create', ['name' => 'X', 'participants' => [self::GROUP]]),
            ToolInputInvalidException::class,
        );
    }
}
