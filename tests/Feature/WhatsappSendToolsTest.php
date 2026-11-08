<?php

namespace Tests\Feature;

use App\Exceptions\WhatsappOperationException;
use App\Jobs\DeliverWhatsappOutbound;
use App\Models\Approval;
use App\Models\ApprovalStatus;
use App\Models\ExecutionStatus;
use App\Models\Tool;
use App\Models\ToolExecution;
use App\Models\User;
use App\Models\WhatsappConsent;
use App\Models\WhatsappOutboundMessage;
use App\Models\WhatsappOutboundStatus;
use App\Models\WhatsappSession;
use App\Services\ApprovalService;
use App\Services\WhatsappOutboundService;
use App\Support\HostResolver;
use App\Support\Settings;
use App\Tools\Exceptions\ToolInputInvalidException;
use App\Tools\Internal\WhatsappSendAudioTool;
use App\Tools\Internal\WhatsappSendDocumentTool;
use App\Tools\Internal\WhatsappSendImageTool;
use App\Tools\Internal\WhatsappSendLocationTool;
use App\Tools\Internal\WhatsappSendTextTool;
use App\Tools\Internal\WhatsappSendVideoTool;
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

class WhatsappSendToolsTest extends TestCase
{
    use FakesWuzapi;
    use RefreshDatabase;
    use SeedsLookups;

    private const string CONTACT = '5511988887777@s.whatsapp.net';

    /**
     * CT-30 send tools: identifier → [class, outbound kind, required scopes, valid input].
     *
     * @var array<string, array{class-string, string, list<string>, array<string, mixed>}>
     */
    private const array TOOLS = [
        'whatsapp.send.text' => [WhatsappSendTextTool::class, 'text', ['envio'], ['to' => self::CONTACT, 'text' => 'Olá!']],
        'whatsapp.send.image' => [WhatsappSendImageTool::class, 'image', ['envio', 'midia'], ['to' => self::CONTACT, 'url' => 'https://cdn.example.com/x.png', 'caption' => 'Foto']],
        'whatsapp.send.video' => [WhatsappSendVideoTool::class, 'video', ['envio', 'midia'], ['to' => self::CONTACT, 'url' => 'https://cdn.example.com/v.mp4']],
        'whatsapp.send.audio' => [WhatsappSendAudioTool::class, 'audio', ['envio', 'midia'], ['to' => self::CONTACT, 'url' => 'https://cdn.example.com/a.ogg']],
        'whatsapp.send.document' => [WhatsappSendDocumentTool::class, 'document', ['envio', 'midia'], ['to' => self::CONTACT, 'url' => 'https://cdn.example.com/d.pdf', 'filename' => 'relatorio.pdf']],
        'whatsapp.send.location' => [WhatsappSendLocationTool::class, 'location', ['envio'], ['to' => self::CONTACT, 'latitude' => -23.55, 'longitude' => -46.63, 'name' => 'Escritório']],
    ];

    private User $user;

    private WhatsappSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();
        $this->fakeWuzapi();
        $this->fakeMediaHost();

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

    private function fakeMediaHost(): void
    {
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                return $host === 'cdn.example.com' ? ['93.184.216.34'] : [];
            }
        });

        Http::fake([
            'https://cdn.example.com/x.png' => Http::response("\x89PNG\r\n\x1a\nfake", 200, ['Content-Type' => 'image/png']),
            'https://cdn.example.com/v.mp4' => Http::response('fake-mp4', 200, ['Content-Type' => 'video/mp4']),
            'https://cdn.example.com/a.ogg' => Http::response('OggS-fake', 200, ['Content-Type' => 'audio/ogg']),
            'https://cdn.example.com/d.pdf' => Http::response('%PDF-1.4 fake', 200, ['Content-Type' => 'application/pdf']),
        ]);
    }

    /**
     * Run a send tool carrying an already granted approval.
     *
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

    /**
     * @return array<string, array{string}>
     */
    public static function toolIdentifiers(): array
    {
        return array_combine(array_keys(self::TOOLS), array_map(fn (string $identifier): array => [$identifier], array_keys(self::TOOLS)));
    }

    public function test_catalogue_registers_the_send_tools_as_write_not_sensitive_with_ct30_scopes(): void
    {
        foreach (self::TOOLS as $identifier => [$class, , $scopes]) {
            $tool = Tool::query()->where('identifier', $identifier)->firstOrFail();
            $handler = app($class);

            $this->assertSame($class, $tool->handler_class);
            $this->assertTrue($tool->is_write, $identifier);
            $this->assertFalse($tool->is_sensitive, $identifier);
            $this->assertTrue($tool->requiresApproval(), $identifier);
            $this->assertFalse($tool->input_schema['additionalProperties'], $identifier);
            $this->assertSame('string', $tool->input_schema['properties']['to']['type'], $identifier);
            $this->assertSame($scopes, $handler->requiredScopes([]), $identifier);
            $this->assertTrue($handler->writesToWhatsapp(), $identifier);
        }
    }

    #[DataProvider('toolIdentifiers')]
    public function test_without_approval_opens_one_pending_approval_and_sends_nothing(string $identifier): void
    {
        $tool = Tool::query()->where('identifier', $identifier)->firstOrFail();

        $result = app(ToolExecutor::class)->execute($tool, self::TOOLS[$identifier][3], new ToolContext($this->user));

        $this->assertSame(ToolResult::PENDING_APPROVAL, $result->status);
        $this->assertSame(1, Approval::query()->where('tool_id', $tool->id)->whereRelation('status', 'slug', ApprovalStatus::PENDING)->count());
        $this->assertSame(0, ToolExecution::query()->where('tool_id', $tool->id)->count());
        $this->assertSame(0, WhatsappOutboundMessage::query()->count());
        Http::assertNothingSent();
    }

    public function test_approving_the_request_executes_once_and_answers_queued(): void
    {
        $tool = Tool::query()->where('identifier', 'whatsapp.send.text')->firstOrFail();
        $pending = app(ToolExecutor::class)->execute($tool, self::TOOLS['whatsapp.send.text'][3], new ToolContext($this->user));

        app(ApprovalService::class)->approve($pending->approval, $this->user);

        $executions = ToolExecution::query()->where('approval_id', $pending->approval->id)->with('status')->get();
        $this->assertCount(1, $executions);
        $this->assertSame(ExecutionStatus::COMPLETED, $executions->first()->status->slug);
        $this->assertSame(WhatsappOutboundStatus::QUEUED, $executions->first()->output['status']);

        $outbound = WhatsappOutboundMessage::query()->sole();
        $this->assertSame($executions->first()->output['outbound_id'], $outbound->id);
        $this->assertSame($pending->approval->id, $outbound->approval_id);
        $this->assertSame(['text' => 'Olá!'], $outbound->payload);
        $this->assertNoWuzapiSend();
        Queue::assertPushed(DeliverWhatsappOutbound::class);
    }

    #[DataProvider('toolIdentifiers')]
    public function test_each_send_tool_enqueues_its_kind_and_payload(string $identifier): void
    {
        [, $kind, , $input] = self::TOOLS[$identifier];

        $result = $this->executeApproved($identifier, $input);

        $this->assertSame(ExecutionStatus::COMPLETED, $result->status, (string) $result->error);
        $this->assertSame(WhatsappOutboundStatus::QUEUED, $result->output['status']);

        $outbound = WhatsappOutboundMessage::query()->findOrFail($result->output['outbound_id']);
        $this->assertSame($kind, $outbound->kind);
        $this->assertSame(self::CONTACT, $outbound->to_jid);
        $this->assertSame($this->user->id, $outbound->user_id);
        $this->assertEqualsCanonicalizing(array_diff_key($input, ['to' => true]), $outbound->payload);
        $this->assertNoWuzapiSend();
    }

    public function test_bare_phone_number_is_normalized_to_a_user_jid(): void
    {
        $result = $this->executeApproved('whatsapp.send.text', ['to' => '+5511988887777', 'text' => 'Oi']);

        $this->assertSame(self::CONTACT, WhatsappOutboundMessage::query()->findOrFail($result->output['outbound_id'])->to_jid);
    }

    public function test_a_list_of_recipients_is_rejected_by_the_schema_without_any_send(): void
    {
        foreach ([[self::CONTACT, '5511977776666@s.whatsapp.net'], self::CONTACT.',5511977776666@s.whatsapp.net'] as $to) {
            $this->assertThrows(
                fn (): mixed => $this->executeApproved('whatsapp.send.text', ['to' => $to, 'text' => 'Oi']),
                ToolInputInvalidException::class,
            );
        }

        $this->assertSame(0, WhatsappOutboundMessage::query()->count());
        Http::assertNothingSent();
    }

    public function test_text_longer_than_4096_characters_is_rejected(): void
    {
        $this->assertThrows(
            fn (): mixed => $this->executeApproved('whatsapp.send.text', ['to' => self::CONTACT, 'text' => str_repeat('a', 4097)]),
            ToolInputInvalidException::class,
        );

        $this->assertSame(0, WhatsappOutboundMessage::query()->count());
    }

    public function test_media_from_a_plain_http_url_fails_with_media_invalid(): void
    {
        $result = $this->executeApproved('whatsapp.send.image', ['to' => self::CONTACT, 'url' => 'http://cdn.example.com/x.png']);

        $this->assertFailedWith($result, WhatsappOperationException::MEDIA_INVALID);
        $this->assertSame(0, WhatsappOutboundMessage::query()->count());
        $this->assertNoWuzapiSend();
        Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://cdn.example.com'));
    }

    #[DataProvider('toolIdentifiers')]
    public function test_kill_switch_fails_with_kill_switch_active_and_no_http(string $identifier): void
    {
        app(Settings::class)->set('whatsapp.kill_switch', 1);

        $this->assertFailedWith($this->executeApproved($identifier, self::TOOLS[$identifier][3]), WhatsappOperationException::KILL_SWITCH_ACTIVE);
        $this->assertSame(0, WhatsappOutboundMessage::query()->count());
        Http::assertNothingSent();
    }

    public function test_media_send_without_the_media_scope_fails_with_consent_required(): void
    {
        WhatsappConsent::query()->where('user_id', $this->user->id)->update(['scopes' => json_encode(['leitura', 'envio'])]);

        $result = $this->executeApproved('whatsapp.send.image', self::TOOLS['whatsapp.send.image'][3]);

        $this->assertFailedWith($result, WhatsappOperationException::CONSENT_REQUIRED);
        $this->assertStringContainsString('midia', (string) $result->error);
        Http::assertNothingSent();
    }
}
