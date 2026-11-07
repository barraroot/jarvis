<?php

namespace Tests\Feature;

use App\Exceptions\WhatsappOperationException;
use App\Jobs\DeliverWhatsappOutbound;
use App\Models\AuditAction;
use App\Models\AuditLog;
use App\Models\ConnectorStatus;
use App\Models\Notification;
use App\Models\NotificationType;
use App\Models\User;
use App\Models\WhatsappConsent;
use App\Models\WhatsappMessage;
use App\Models\WhatsappOutboundMessage;
use App\Models\WhatsappOutboundRecipient;
use App\Models\WhatsappOutboundStatus;
use App\Models\WhatsappSchedule;
use App\Models\WhatsappSession;
use App\Services\WhatsappOutboundService;
use App\Support\HostResolver;
use App\Support\Settings;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesWuzapi;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class WhatsappOutboundDeliveryTest extends TestCase
{
    use FakesWuzapi;
    use RefreshDatabase;
    use SeedsLookups;

    private const string CONTACT = '5511988887777@s.whatsapp.net';

    private const string TEXT = 'Mensagem confidencial para o contato';

    private const string PNG = "\x89PNG\r\n\x1a\n0000IHDR-fake-image";

    private WhatsappSession $session;

    private User $user;

    /**
     * When true, `POST /chat/send/text` answers 500 with the session token in the body.
     */
    private bool $textSendFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();
        $this->fakeWuzapi([
            'POST /chat/send/text' => fn (): mixed => $this->textSendFails
                ? Http::response(['code' => 500, 'error' => 'boom token='.$this->session->token, 'success' => false], 500)
                : Http::response(['code' => 200, 'data' => ['Details' => 'Sent', 'Id' => '3EB0SENT0000000000001'], 'success' => true]),
        ]);

        Queue::fake();
        Sleep::fake();

        $this->session = WhatsappSession::factory()->connected()->withConsent()->create();
        $this->user = $this->session->user;

        // Locks live on the Redis lock connection, which `Cache::flush()` does not
        // clear, and session ids repeat across runs after `migrate:fresh`.
        (new UniqueLock(Cache::store()))->release(new DeliverWhatsappOutbound($this->session->id));
        Cache::lock(WhatsappOutboundService::deliveryLockKey($this->session->id))->forceRelease();
        Cache::lock(WhatsappOutboundService::guardLockKey($this->session->id))->forceRelease();
    }

    protected function tearDown(): void
    {
        Queue::releaseUniqueJobLocks();

        parent::tearDown();
    }

    private function service(): WhatsappOutboundService
    {
        return app(WhatsappOutboundService::class);
    }

    /**
     * @return array{outbound_id: int, status: string}
     */
    private function enqueueText(string $text = self::TEXT, ?WhatsappSchedule $schedule = null): array
    {
        return $this->service()->enqueue($this->user, 'text', self::CONTACT, ['text' => $text], schedule: $schedule);
    }

    /**
     * Run the delivery jobs pushed so far, as a worker would (the unique lock
     * is released before processing).
     */
    private function runDeliveries(): void
    {
        $processed = 0;

        while (($job = Queue::pushed(DeliverWhatsappOutbound::class)->values()->get($processed)) !== null) {
            $processed++;

            if ($job->delay !== null) {
                $this->travelTo($job->delay);
            }

            (new UniqueLock(Cache::store()))->release($job);

            $job->handle($this->service());
        }
    }

    private function outbound(int $id): WhatsappOutboundMessage
    {
        return WhatsappOutboundMessage::query()->with('status')->findOrFail($id);
    }

    private function audits(string $action): int
    {
        return AuditLog::query()->where('audit_action_id', AuditAction::idFor($action))->count();
    }

    private function notifications(string $type): int
    {
        return Notification::query()
            ->where('user_id', $this->user->id)
            ->where('notification_type_id', NotificationType::idFor($type))
            ->count();
    }

    private function expectOperationError(callable $callback, string $reason): void
    {
        try {
            $callback();
        } catch (WhatsappOperationException $exception) {
            $this->assertSame($reason, $exception->reason);

            return;
        }

        $this->fail("Expected [{$reason}].");
    }

    private function fakeMediaHost(): void
    {
        config([
            'services.wuzapi.media_max_bytes.image' => 1024,
            'services.wuzapi.media_max_bytes.audio' => 1024,
        ]);

        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                return $host === 'cdn.example.com' ? ['93.184.216.34'] : [];
            }
        });

        Http::fake([
            'https://cdn.example.com/x.png' => Http::response(self::PNG, 200, ['Content-Type' => 'image/png']),
            'https://cdn.example.com/a.ogg' => Http::response('OggS-fake-audio', 200, ['Content-Type' => 'audio/ogg']),
        ]);
    }

    public function test_approved_send_is_queued_then_sent_with_upstream_id_and_one_audit_without_text(): void
    {
        $result = $this->enqueueText();

        $this->assertSame('queued', $result['status']);
        $this->assertSame(WhatsappOutboundStatus::QUEUED, $this->outbound($result['outbound_id'])->statusSlug());
        $this->assertSame(1, WhatsappOutboundRecipient::query()->where('whatsapp_outbound_message_id', $result['outbound_id'])->count());
        Queue::assertPushed(DeliverWhatsappOutbound::class, fn (DeliverWhatsappOutbound $job): bool => $job->whatsappSessionId === $this->session->id);
        $this->assertNoWuzapiSend();

        $this->runDeliveries();

        $outbound = $this->outbound($result['outbound_id']);
        $this->assertSame(WhatsappOutboundStatus::SENT, $outbound->statusSlug());
        $this->assertSame('3EB0SENT0000000000001', $outbound->upstream_id);
        $this->assertNull($outbound->reason);
        $this->assertNotNull($outbound->sent_at);
        $this->assertNotNull($outbound->finished_at);
        $this->assertWuzapiCalled('POST', '/chat/send/text');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/chat/send/text')
            && $request['Phone'] === self::CONTACT && $request['Body'] === self::TEXT);

        $audits = AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::WHATSAPP_MESSAGE_SENT))->get();
        $this->assertCount(1, $audits);
        $this->assertStringNotContainsString(self::TEXT, (string) json_encode($audits->first()->properties));
        $this->assertSame($outbound->id, $audits->first()->properties['outbound_id']);
        $this->assertSame(0, Notification::query()->count());
        $this->assertNotNull($this->session->fresh()->next_delivery_at);
    }

    public function test_enqueue_dispatches_delivery_after_commit(): void
    {
        $this->enqueueText();

        Queue::assertPushed(DeliverWhatsappOutbound::class, fn (DeliverWhatsappOutbound $job): bool => $job->afterCommit === true);
    }

    public function test_upstream_error_marks_failed_notifies_once_and_tries_once(): void
    {
        $this->textSendFails = true;

        $result = $this->enqueueText();
        $this->runDeliveries();

        $outbound = $this->outbound($result['outbound_id']);
        $this->assertSame(WhatsappOutboundStatus::FAILED, $outbound->statusSlug());
        $this->assertSame(WhatsappOperationException::UPSTREAM_ERROR, $outbound->reason);
        $this->assertNull($outbound->upstream_id);
        $this->assertWuzapiCalled('POST', '/chat/send/text', 1);
        $this->assertSame(1, $this->notifications(NotificationType::WHATSAPP_SEND_FAILED));
        $this->assertSame(0, $this->audits(AuditAction::WHATSAPP_MESSAGE_SENT));
        $this->assertCount(1, Queue::pushed(DeliverWhatsappOutbound::class));

        $notification = Notification::query()->firstOrFail();
        $this->assertStringNotContainsString($this->session->token, $notification->title.$notification->body);
    }

    public function test_kill_switch_after_enqueue_blocks_at_delivery_without_sending(): void
    {
        $result = $this->enqueueText();

        app(Settings::class)->set('whatsapp.kill_switch', 1);

        $this->runDeliveries();

        $outbound = $this->outbound($result['outbound_id']);
        $this->assertSame(WhatsappOutboundStatus::BLOCKED, $outbound->statusSlug());
        $this->assertSame(WhatsappOperationException::KILL_SWITCH_ACTIVE, $outbound->reason);
        $this->assertSame(1, $this->notifications(NotificationType::WHATSAPP_SEND_FAILED));
        $this->assertSame(1, $this->audits(AuditAction::WHATSAPP_SEND_BLOCKED));
        $this->assertNoWuzapiSend();
        $this->assertWuzapiCalled('POST', '/chat/presence', 0);

        app(Settings::class)->set('whatsapp.kill_switch', 0);
        $this->runDeliveries();

        $this->assertSame(WhatsappOutboundStatus::BLOCKED, $this->outbound($result['outbound_id'])->statusSlug());
        $this->assertNoWuzapiSend();
    }

    public function test_suspension_after_enqueue_blocks_at_delivery(): void
    {
        $result = $this->enqueueText();

        $this->session->forceFill(['suspended_at' => now()])->save();
        $this->runDeliveries();

        $outbound = $this->outbound($result['outbound_id']);
        $this->assertSame(WhatsappOutboundStatus::BLOCKED, $outbound->statusSlug());
        $this->assertSame(WhatsappOperationException::SESSION_SUSPENDED, $outbound->reason);
        $this->assertSame(1, $this->audits(AuditAction::WHATSAPP_SEND_BLOCKED));
        $this->assertNoWuzapiSend();
    }

    public function test_revoked_consent_and_lost_connection_block_at_delivery_with_audit(): void
    {
        $first = $this->enqueueText();
        $second = $this->service()->enqueue($this->user, 'text', '5511977776666@s.whatsapp.net', ['text' => 'Oi']);

        WhatsappConsent::query()->where('user_id', $this->user->id)->update(['scopes' => [WhatsappConsent::SCOPE_READ]]);
        $this->runDeliveries();

        $this->assertSame(WhatsappOperationException::CONSENT_REQUIRED, $this->outbound($first['outbound_id'])->reason);
        $this->assertSame(WhatsappOperationException::CONSENT_REQUIRED, $this->outbound($second['outbound_id'])->reason);
        $this->assertSame(2, $this->audits(AuditAction::WHATSAPP_SEND_BLOCKED));
        $this->assertSame(2, $this->notifications(NotificationType::WHATSAPP_SEND_FAILED));
        $this->assertNoWuzapiSend();

        WhatsappConsent::query()->where('user_id', $this->user->id)->update(['scopes' => WhatsappConsent::SCOPES]);
        $third = $this->enqueueText('Outra');
        $this->session->forceFill(['connector_status_id' => ConnectorStatus::idFor(ConnectorStatus::DISCONNECTED)])->save();
        $this->runDeliveries();

        $this->assertSame(WhatsappOperationException::NOT_CONNECTED, $this->outbound($third['outbound_id'])->reason);
        $this->assertNoWuzapiSend();
    }

    public function test_enqueue_rejections_create_no_row_and_call_no_wuzapi(): void
    {
        app(Settings::class)->set('whatsapp.kill_switch', 1);
        $this->expectOperationError(fn () => $this->enqueueText(), WhatsappOperationException::KILL_SWITCH_ACTIVE);
        app(Settings::class)->set('whatsapp.kill_switch', 0);

        app(Settings::class)->set('whatsapp.max_sends_per_minute', 1);
        $this->enqueueText();
        $this->expectOperationError(fn () => $this->enqueueText(), WhatsappOperationException::RATE_LIMITED);

        $this->session->forceFill(['connector_status_id' => ConnectorStatus::idFor(ConnectorStatus::DISCONNECTED)])->save();
        $this->expectOperationError(fn () => $this->enqueueText(), WhatsappOperationException::NOT_CONNECTED);

        WhatsappConsent::query()->where('user_id', $this->user->id)->update(['scopes' => [WhatsappConsent::SCOPE_SEND]]);
        $this->expectOperationError(
            fn () => $this->service()->enqueue($this->user, 'image', self::CONTACT, ['url' => 'https://cdn.example.com/x.png']),
            WhatsappOperationException::CONSENT_REQUIRED,
        );

        $this->assertSame(1, WhatsappOutboundMessage::query()->count());
        $this->assertNoWuzapiSend();
    }

    public function test_user_without_session_is_not_connected(): void
    {
        $user = User::factory()->create();
        WhatsappConsent::factory()->for($user)->create();

        $this->expectOperationError(
            fn () => $this->service()->enqueue($user, 'text', self::CONTACT, ['text' => 'Oi']),
            WhatsappOperationException::NOT_CONNECTED,
        );
    }

    public function test_media_is_validated_at_enqueue_and_downloaded_again_at_delivery(): void
    {
        $this->fakeMediaHost();

        $this->expectOperationError(
            fn () => $this->service()->enqueue($this->user, 'image', self::CONTACT, ['url' => 'http://cdn.example.com/x.png']),
            WhatsappOperationException::MEDIA_INVALID,
        );
        $this->assertSame(0, WhatsappOutboundMessage::query()->count());

        $result = $this->service()->enqueue($this->user, 'image', self::CONTACT, ['url' => 'https://cdn.example.com/x.png', 'caption' => 'Foto']);
        $this->runDeliveries();

        $this->assertSame(WhatsappOutboundStatus::SENT, $this->outbound($result['outbound_id'])->statusSlug());
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/chat/send/image')
            && $request['Image'] === 'data:image/png;base64,'.base64_encode(self::PNG)
            && $request['Caption'] === 'Foto');
        $this->assertCount(2, Http::recorded(fn (Request $request): bool => str_starts_with($request->url(), 'https://cdn.example.com/')));
        $this->assertWuzapiCalled('POST', '/chat/presence', 0);
    }

    public function test_media_unavailable_at_delivery_is_blocked(): void
    {
        $this->fakeMediaHost();

        $result = $this->service()->enqueue($this->user, 'image', self::CONTACT, ['url' => 'https://cdn.example.com/x.png']);

        config(['services.wuzapi.media_max_bytes.image' => 4]);
        $this->runDeliveries();

        $this->assertSame(WhatsappOperationException::MEDIA_INVALID, $this->outbound($result['outbound_id'])->reason);
        $this->assertSame(1, $this->audits(AuditAction::WHATSAPP_SEND_BLOCKED));
        $this->assertNoWuzapiSend();
    }

    public function test_audio_signals_recording_presence(): void
    {
        $this->fakeMediaHost();

        $this->service()->enqueue($this->user, 'audio', self::CONTACT, ['url' => 'https://cdn.example.com/a.ogg']);
        $this->runDeliveries();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/chat/presence')
            && $request['State'] === 'composing' && $request['Media'] === 'audio');
        $this->assertWuzapiCalled('POST', '/chat/send/audio');
    }

    public function test_sent_message_links_an_already_stored_echo(): void
    {
        $echo = WhatsappMessage::factory()->create([
            'whatsapp_session_id' => $this->session->id,
            'user_id' => $this->user->id,
            'upstream_id' => '3EB0SENT0000000000001',
            'from_me' => true,
            'whatsapp_outbound_message_id' => null,
        ]);

        $result = $this->enqueueText();
        $this->runDeliveries();

        $this->assertSame($result['outbound_id'], $echo->fresh()->whatsapp_outbound_message_id);
    }

    public function test_schedule_occurrence_audits_its_result_and_notifies_on_failure(): void
    {
        $schedule = WhatsappSchedule::factory()->for($this->user)->create(['whatsapp_session_id' => $this->session->id]);

        $sent = $this->service()->enqueue($this->user, 'text', self::CONTACT, ['text' => 'Bom dia'], schedule: $schedule, meta: ['discarded_occurrences' => 2]);
        $this->runDeliveries();

        $audit = AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::WHATSAPP_SCHEDULE_EXECUTED))->sole();
        $this->assertSame('sent', $audit->properties['result']);
        $this->assertSame(2, $audit->properties['discarded']);
        $this->assertSame($schedule->id, $audit->properties['schedule_id']);
        $this->assertSame($sent['outbound_id'], $audit->properties['outbound_id']);

        $this->textSendFails = true;
        $this->travel(1)->hours();
        $this->service()->enqueue($this->user, 'text', self::CONTACT, ['text' => 'Bom dia'], schedule: $schedule);
        $this->runDeliveries();

        $this->assertSame(2, $this->audits(AuditAction::WHATSAPP_SCHEDULE_EXECUTED));
        $this->assertSame(1, $this->notifications(NotificationType::WHATSAPP_SCHEDULE_RUN_FAILED));
        $this->assertSame(0, $this->notifications(NotificationType::WHATSAPP_SEND_FAILED));
    }

    public function test_held_delivery_lock_prevents_a_concurrent_delivery(): void
    {
        $result = $this->enqueueText();

        $lock = Cache::lock(WhatsappOutboundService::deliveryLockKey($this->session->id), 60);
        $this->assertTrue($lock->get());

        $job = Queue::pushed(DeliverWhatsappOutbound::class)->first();
        (new UniqueLock(Cache::store()))->release($job);
        $job->handle($this->service());

        $this->assertSame(WhatsappOutboundStatus::QUEUED, $this->outbound($result['outbound_id'])->statusSlug());
        $this->assertNoWuzapiSend();
        $this->assertCount(2, Queue::pushed(DeliverWhatsappOutbound::class));

        $lock->release();
        $this->runDeliveries();

        $this->assertSame(WhatsappOutboundStatus::SENT, $this->outbound($result['outbound_id'])->statusSlug());
        $this->assertWuzapiCalled('POST', '/chat/send/text', 1);
    }

    public function test_job_is_unique_per_session_with_a_single_attempt(): void
    {
        $job = new DeliverWhatsappOutbound($this->session->id);

        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);
        $this->assertSame(1, $job->tries);
        $this->assertSame((string) $this->session->id, $job->uniqueId());

        $this->enqueueText();
        $this->enqueueText('Segunda');

        $this->assertCount(1, Queue::pushed(DeliverWhatsappOutbound::class));
    }
}
