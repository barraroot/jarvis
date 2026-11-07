<?php

namespace Tests\Feature;

use App\Models\ConnectorStatus;
use App\Models\User;
use App\Models\WhatsappConsent;
use App\Models\WhatsappMessage;
use App\Models\WhatsappOutboundMessage;
use App\Models\WhatsappOutboundRecipient;
use App\Models\WhatsappOutboundStatus;
use App\Models\WhatsappSchedule;
use App\Models\WhatsappScheduleStatus;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class WhatsappModelsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    public function test_session_secrets_are_encrypted_at_rest_and_hidden_from_serialization(): void
    {
        $session = WhatsappSession::factory()->create([
            'token' => 'plain-token-value',
            'hmac_key' => 'plain-hmac-value',
            'webhook_key' => 'plain-webhook-key',
        ]);

        $raw = DB::table('whatsapp_sessions')->where('id', $session->id)->first();

        foreach (['token' => 'plain-token-value', 'hmac_key' => 'plain-hmac-value', 'webhook_key' => 'plain-webhook-key'] as $column => $plain) {
            $this->assertNotSame($plain, $raw->{$column});
            $this->assertStringNotContainsString($plain, $raw->{$column});
            $this->assertSame($plain, $session->fresh()->{$column});
        }

        $serialized = $session->fresh()->toArray();

        foreach (['token', 'hmac_key', 'webhook_key', 'webhook_key_hash'] as $key) {
            $this->assertArrayNotHasKey($key, $serialized);
        }

        $json = $session->fresh()->toJson();
        $this->assertStringNotContainsString('plain-token-value', $json);
        $this->assertStringNotContainsString('plain-hmac-value', $json);
        $this->assertStringNotContainsString('plain-webhook-key', $json);
    }

    public function test_session_is_found_by_webhook_key_hash(): void
    {
        $session = WhatsappSession::factory()->create(['webhook_key' => 'webhook-key-one']);
        WhatsappSession::factory()->create(['webhook_key' => 'webhook-key-two']);

        $this->assertSame(hash('sha256', 'webhook-key-one'), $session->webhook_key_hash);
        $this->assertTrue(WhatsappSession::query()->forWebhookKey('webhook-key-one')->sole()->is($session));
        $this->assertFalse(WhatsappSession::query()->forWebhookKey('unknown')->exists());

        $session->update(['webhook_key' => 'rotated-key']);

        $this->assertFalse(WhatsappSession::query()->forWebhookKey('webhook-key-one')->exists());
        $this->assertTrue(WhatsappSession::query()->forWebhookKey('rotated-key')->sole()->is($session));
    }

    public function test_session_status_and_suspension(): void
    {
        $pending = WhatsappSession::factory()->create();
        $connected = WhatsappSession::factory()->connected()->suspended()->create();

        $this->assertSame(ConnectorStatus::PENDING, $pending->statusSlug());
        $this->assertFalse($pending->isConnected());
        $this->assertFalse($pending->isSuspended());

        $this->assertSame(ConnectorStatus::CONNECTED, $connected->statusSlug());
        $this->assertTrue($connected->isConnected());
        $this->assertTrue($connected->isSuspended());
    }

    public function test_consent_is_valid_only_when_current_and_not_revoked(): void
    {
        $this->assertSame(1, WhatsappConsent::CURRENT_VERSION);
        $this->assertSame(['leitura', 'envio', 'grupos', 'midia'], WhatsappConsent::SCOPES);

        $this->assertTrue(WhatsappConsent::factory()->create()->isValid());
        $this->assertFalse(WhatsappConsent::factory()->revoked()->create()->isValid());
        $this->assertFalse(WhatsappConsent::factory()->create(['version' => 0])->isValid());
        $this->assertFalse(WhatsappConsent::factory()->outdated()->create()->isValid());
    }

    public function test_missing_scopes_lists_required_scopes_not_accepted(): void
    {
        $consent = WhatsappConsent::factory()->scopes(['envio'])->create();

        $this->assertSame(['midia'], $consent->missingScopes(['envio', 'midia']));
        $this->assertSame([], $consent->missingScopes(['envio']));
        $this->assertSame(['leitura', 'grupos'], $consent->missingScopes(['leitura', 'envio', 'grupos']));

        $this->assertTrue($consent->allows(['envio']));
        $this->assertFalse($consent->allows(['envio', 'midia']));

        $consent->update(['revoked_at' => now()]);
        $this->assertFalse($consent->allows(['envio']));
    }

    public function test_schedule_constants_and_helpers(): void
    {
        $this->assertSame('America/Sao_Paulo', WhatsappSchedule::TIMEZONE);
        $this->assertSame(['image', 'video', 'audio', 'document'], WhatsappSchedule::MEDIA_KINDS);

        $once = WhatsappSchedule::factory()->create();
        $recurring = WhatsappSchedule::factory()->recurring()->paused()->create(['kind' => 'image']);

        $this->assertFalse($once->isRecurring());
        $this->assertFalse($once->isMedia());
        $this->assertSame(WhatsappScheduleStatus::ACTIVE, $once->statusSlug());

        $this->assertTrue($recurring->isRecurring());
        $this->assertTrue($recurring->isMedia());
        $this->assertSame(WhatsappScheduleStatus::PAUSED, $recurring->statusSlug());
    }

    public function test_user_and_session_relations(): void
    {
        $user = User::factory()->create();
        $session = WhatsappSession::factory()->for($user)->withConsent(['leitura'])->create();
        $schedule = WhatsappSchedule::factory()->forSession($session)->create();
        $outbound = WhatsappOutboundMessage::factory()->forSession($session)->sent()->create(['whatsapp_schedule_id' => $schedule->id]);
        $recipient = WhatsappOutboundRecipient::query()->create([
            'whatsapp_outbound_message_id' => $outbound->id,
            'whatsapp_session_id' => $session->id,
            'jid' => $outbound->to_jid,
            'is_non_contact' => true,
            'created_at' => now(),
        ]);
        $message = WhatsappMessage::factory()->forSession($session)->create([
            'whatsapp_outbound_message_id' => $outbound->id,
            'upstream_id' => $outbound->upstream_id,
        ]);

        $this->assertTrue($user->whatsappSession->is($session));
        $this->assertSame(['leitura'], $user->whatsappConsent->scopes);
        $this->assertTrue($user->whatsappConsent->isValid());
        $this->assertTrue($user->whatsappSchedules()->sole()->is($schedule));

        $this->assertTrue($session->schedules()->sole()->is($schedule));
        $this->assertTrue($session->outboundMessages()->sole()->is($outbound));
        $this->assertTrue($session->outboundRecipients()->sole()->is($recipient));
        $this->assertTrue($session->messages()->sole()->is($message));

        $this->assertTrue($schedule->session->is($session));
        $this->assertTrue($schedule->outboundMessages()->sole()->is($outbound));
        $this->assertTrue($outbound->schedule->is($schedule));
        $this->assertSame(WhatsappOutboundStatus::SENT, $outbound->statusSlug());
        $this->assertTrue($outbound->recipients()->sole()->is($recipient));
        $this->assertTrue($recipient->outboundMessage->is($outbound));
        $this->assertTrue($recipient->is_non_contact);
        $this->assertNull($recipient->updated_at);
        $this->assertTrue($message->outboundMessage->is($outbound));
        $this->assertTrue($message->user->is($user));
    }
}
