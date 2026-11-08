<?php

namespace Tests\Feature;

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
use App\Tools\ToolAdapter;
use App\Tools\ToolContext;
use App\Tools\ToolExecutor;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Laravel\Ai\Tools\Request;
use Tests\Concerns\FakesWuzapi;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

/**
 * RF-15: a `whatsapp.*` write tool asked by an agent opens a pending
 * approval and only runs — exactly once — after the owner approves it.
 * RF-10/RF-13: an approval granted before the consent was revoked (or a
 * scope removed) does not authorize anything: the run fails with
 * `whatsapp_consent_required` and wuzapi is never called.
 */
class WhatsappApprovalFlowTest extends TestCase
{
    use FakesWuzapi;
    use RefreshDatabase;
    use SeedsLookups;

    private const string CONTACT = '5511988887777@s.whatsapp.net';

    private const array INPUT = ['to' => self::CONTACT, 'text' => 'Chego em 10 minutos.'];

    private User $user;

    private WhatsappSession $session;

    private Tool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();
        $this->fakeWuzapi();

        config(['broadcasting.default' => 'null']);

        Queue::fake();
        Sleep::fake();

        $this->artisan('jarvis:tools:sync-internal')->assertSuccessful();

        $this->session = WhatsappSession::factory()->connected()->withConsent()->create(['wuzapi_user_id' => 'wuzapi-user-1']);
        $this->user = $this->session->user;
        $this->tool = Tool::query()->where('identifier', 'whatsapp.send.text')->firstOrFail();

        (new UniqueLock(Cache::store()))->release(new DeliverWhatsappOutbound($this->session->id));
        Cache::lock(WhatsappOutboundService::deliveryLockKey($this->session->id))->forceRelease();
        Cache::lock(WhatsappOutboundService::guardLockKey($this->session->id))->forceRelease();
    }

    protected function tearDown(): void
    {
        Queue::releaseUniqueJobLocks();

        parent::tearDown();
    }

    public function test_agent_request_waits_for_approval_then_runs_and_sends_exactly_once(): void
    {
        $adapter = $this->adapter();

        $this->assertNotNull($adapter->shouldRequestApproval(new Request(self::INPUT)));

        $approval = Approval::query()->with('status')->sole();
        $this->assertSame(ApprovalStatus::PENDING, $approval->status->slug);
        $this->assertSame($this->tool->id, $approval->tool_id);
        $this->assertSame(0, ToolExecution::query()->count());
        $this->assertSame(0, WhatsappOutboundMessage::query()->count());
        Http::assertNothingSent();

        $this->actingAs($this->user);
        $this->postJson("/api/v1/approvals/{$approval->id}/approve")->assertOk();
        $this->assertFalse($this->postJson("/api/v1/approvals/{$approval->id}/approve")->isSuccessful(), 'An approval is decided once.');

        $execution = ToolExecution::query()->with('status')->sole();
        $this->assertSame($approval->id, $execution->approval_id);
        $this->assertSame(ExecutionStatus::COMPLETED, $execution->status->slug);
        $this->assertSame(WhatsappOutboundStatus::QUEUED, $execution->output['status']);

        $outbound = WhatsappOutboundMessage::query()->sole();
        $this->assertSame($approval->id, $outbound->approval_id);
        $this->assertNoWuzapiSend();

        $this->runDeliveries();

        $this->assertSame(WhatsappOutboundStatus::SENT, $outbound->fresh()->load('status')->status->slug);
        $this->assertWuzapiCalled('POST', '/chat/send/text', 1);
        $this->assertSame(1, ToolExecution::query()->count());
        $this->assertSame(1, WhatsappOutboundMessage::query()->count());
    }

    public function test_approval_granted_before_revocation_fails_with_consent_required_without_http(): void
    {
        $approval = $this->approvedApproval();

        $this->actingAs($this->user);
        $this->deleteJson('/api/v1/whatsapp/consent')->assertOk();

        $callsBefore = Http::recorded()->count();

        $result = app(ToolExecutor::class)->execute($this->tool, self::INPUT, ToolContext::forApproval($approval->fresh()));

        $this->assertSame(ExecutionStatus::FAILED, $result->status);
        $this->assertStringContainsString('whatsapp_consent_required', (string) $result->error);
        $this->assertSame($approval->id, $result->execution->approval_id);
        $this->assertSame(0, WhatsappOutboundMessage::query()->count());
        $this->assertSame($callsBefore, Http::recorded()->count(), 'A refused run must not call wuzapi.');
    }

    public function test_send_queued_before_revocation_is_blocked_at_delivery_without_sending(): void
    {
        $result = app(ToolExecutor::class)->execute($this->tool, self::INPUT, ToolContext::forApproval($this->approvedApproval()));
        $this->assertTrue($result->isCompleted(), (string) $result->error);

        $this->actingAs($this->user);
        $this->deleteJson('/api/v1/whatsapp/consent')->assertOk();

        $this->runDeliveries();

        $outbound = WhatsappOutboundMessage::query()->with('status')->sole();
        $this->assertSame(WhatsappOutboundStatus::BLOCKED, $outbound->status->slug);
        $this->assertStringContainsString('whatsapp_consent_required', (string) $outbound->reason);
        $this->assertNoWuzapiSend();
    }

    public function test_approval_granted_before_a_scope_was_removed_fails_with_consent_required(): void
    {
        $approval = $this->approvedApproval();

        $this->actingAs($this->user);
        $this->postJson('/api/v1/whatsapp/consent', ['version' => WhatsappConsent::CURRENT_VERSION, 'scopes' => [WhatsappConsent::SCOPE_READ]])
            ->assertOk();

        $result = app(ToolExecutor::class)->execute($this->tool, self::INPUT, ToolContext::forApproval($approval->fresh()));

        $this->assertSame(ExecutionStatus::FAILED, $result->status);
        $this->assertStringContainsString('whatsapp_consent_required', (string) $result->error);
        $this->assertSame(0, WhatsappOutboundMessage::query()->count());
        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/chat/'));
    }

    private function adapter(): ToolAdapter
    {
        return new ToolAdapter($this->tool, app(ToolExecutor::class), app(ApprovalService::class), new ToolContext($this->user));
    }

    /**
     * An approval opened by the agent request and granted by the owner,
     * with its own execution held back (the listener is bypassed).
     */
    private function approvedApproval(): Approval
    {
        $this->assertNotNull($this->adapter()->shouldRequestApproval(new Request(self::INPUT)));

        $approval = Approval::query()->sole();
        $approval->update([
            'approval_status_id' => ApprovalStatus::idFor(ApprovalStatus::APPROVED),
            'decided_at' => now(),
            'decided_by_user_id' => $this->user->id,
        ]);

        return $approval->fresh();
    }

    private function runDeliveries(): void
    {
        foreach (Queue::pushed(DeliverWhatsappOutbound::class) as $job) {
            (new UniqueLock(Cache::store()))->release($job);

            $job->handle(app(WhatsappOutboundService::class));
        }
    }
}
