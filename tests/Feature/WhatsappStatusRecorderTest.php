<?php

namespace Tests\Feature;

use App\Events\Whatsapp\SessionStatusChanged;
use App\Models\ConnectorStatus;
use App\Models\Notification;
use App\Models\NotificationType;
use App\Models\WhatsappSession;
use App\Services\WhatsappStatusRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class WhatsappStatusRecorderTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();
    }

    private function recorder(): WhatsappStatusRecorder
    {
        return app(WhatsappStatusRecorder::class);
    }

    public function test_pending_to_connected_broadcasts_once_on_the_owner_channel(): void
    {
        Event::fake([SessionStatusChanged::class]);
        $this->freezeSecond();
        $session = WhatsappSession::factory()->create();

        $changed = $this->recorder()->record($session, ConnectorStatus::CONNECTED, checked: true, phoneMasked: '+55 11 ****-7777');

        $this->assertTrue($changed);
        $session->refresh();
        $this->assertSame(ConnectorStatus::CONNECTED, $session->statusSlug());
        $this->assertTrue($session->connected_at->equalTo(now()));
        $this->assertTrue($session->last_checked_at->equalTo(now()));
        $this->assertSame('+55 11 ****-7777', $session->phone_masked);
        $this->assertNull($session->last_error);

        Event::assertDispatchedTimes(SessionStatusChanged::class, 1);
        Event::assertDispatched(SessionStatusChanged::class, function (SessionStatusChanged $event) use ($session): bool {
            return $event->broadcastAs() === 'whatsapp.session.status.changed'
                && $event->broadcastOn()[0]->name === "private-user.{$session->user_id}"
                && count($event->broadcastOn()) === 1
                && $event->broadcastWith() === [
                    'session_id' => $session->id,
                    'status' => ConnectorStatus::CONNECTED,
                    'previous_status' => ConnectorStatus::PENDING,
                    'suspended' => false,
                    'error' => null,
                    'at' => now()->toIso8601String(),
                ];
        });
    }

    public function test_same_status_does_not_broadcast(): void
    {
        Event::fake([SessionStatusChanged::class]);
        $session = WhatsappSession::factory()->connected()->create(['connected_at' => now()->subDay()]);
        $connectedAt = $session->connected_at;

        $changed = $this->recorder()->record($session, ConnectorStatus::CONNECTED, checked: true);

        $this->assertFalse($changed);
        $this->assertTrue($session->refresh()->connected_at->equalTo($connectedAt));
        Event::assertNotDispatched(SessionStatusChanged::class);
    }

    public function test_entering_error_notifies_owner_once_and_redacts_secrets(): void
    {
        Event::fake([SessionStatusChanged::class]);
        $session = WhatsappSession::factory()->create(['token' => 'session-secret-token-0123456789']);

        $this->recorder()->record($session, ConnectorStatus::ERROR, 'Falha com session-secret-token-0123456789 '.str_repeat('x', 600), checked: true);

        $session->refresh();
        $this->assertSame(ConnectorStatus::ERROR, $session->statusSlug());
        $this->assertStringNotContainsString('session-secret-token-0123456789', $session->last_error);
        $this->assertStringContainsString('[redacted]', $session->last_error);
        $this->assertLessThanOrEqual(WhatsappStatusRecorder::ERROR_MAX_LENGTH, mb_strlen($session->last_error));

        Event::assertDispatched(SessionStatusChanged::class, fn (SessionStatusChanged $event): bool => $event->broadcastWith()['status'] === ConnectorStatus::ERROR
            && $event->broadcastWith()['error'] === $session->last_error
            && $event->broadcastWith()['previous_status'] === ConnectorStatus::PENDING);

        $this->recorder()->record($session, ConnectorStatus::ERROR, 'Falha de novo', checked: true);
        $this->assertSame('Falha de novo', $session->refresh()->last_error);

        $notifications = Notification::query()
            ->where('notification_type_id', NotificationType::idFor(NotificationType::CONNECTOR_ERROR))
            ->get();
        $this->assertCount(1, $notifications);
        $this->assertSame($session->user_id, $notifications->first()->user_id);

        Event::assertDispatchedTimes(SessionStatusChanged::class, 1);
    }

    public function test_leaving_error_clears_the_error(): void
    {
        $session = WhatsappSession::factory()->create([
            'connector_status_id' => ConnectorStatus::idFor(ConnectorStatus::ERROR),
            'last_error' => 'Falha antiga',
        ]);

        $this->recorder()->record($session, ConnectorStatus::DISCONNECTED);

        $session->refresh();
        $this->assertSame(ConnectorStatus::DISCONNECTED, $session->statusSlug());
        $this->assertNull($session->last_error);
        $this->assertNull($session->last_checked_at);
    }

    public function test_payload_has_only_the_contract_keys_and_no_secrets(): void
    {
        Event::fake([SessionStatusChanged::class]);
        $session = WhatsappSession::factory()->create();

        $this->recorder()->record($session, ConnectorStatus::DISCONNECTED);

        Event::assertDispatched(SessionStatusChanged::class, function (SessionStatusChanged $event) use ($session): bool {
            $payload = $event->broadcastWith();
            $json = json_encode($payload);

            $this->assertSame(['session_id', 'status', 'previous_status', 'suspended', 'error', 'at'], array_keys($payload));

            foreach ([$session->token, $session->hmac_key, $session->webhook_key, $session->webhook_key_hash] as $secret) {
                $this->assertStringNotContainsString($secret, $json);
            }

            return true;
        });
    }

    public function test_broadcast_suspension_keeps_status_and_flags_suspended(): void
    {
        Event::fake([SessionStatusChanged::class]);
        $session = WhatsappSession::factory()->connected()->create(['suspended_at' => now()]);

        $this->recorder()->broadcastSuspension($session);

        Event::assertDispatched(SessionStatusChanged::class, fn (SessionStatusChanged $event): bool => $event->broadcastWith()['status'] === ConnectorStatus::CONNECTED
            && $event->broadcastWith()['previous_status'] === ConnectorStatus::CONNECTED
            && $event->broadcastWith()['suspended'] === true);
    }
}
