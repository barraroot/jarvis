<?php

namespace Tests\Feature;

use App\Exceptions\WhatsappOperationException;
use App\Models\AuditAction;
use App\Models\AuditLog;
use App\Models\WhatsappMessage;
use App\Models\WhatsappOutboundMessage;
use App\Models\WhatsappOutboundRecipient;
use App\Models\WhatsappSession;
use App\Services\WhatsappContactDirectory;
use App\Services\WhatsappSendGuard;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\FakesWuzapi;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class WhatsappSendGuardTest extends TestCase
{
    use FakesWuzapi;
    use RefreshDatabase;
    use SeedsLookups;

    private const string CONTACT = '5511988887777@s.whatsapp.net';

    private WhatsappSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();
        $this->fakeWuzapi();

        $this->session = WhatsappSession::factory()->connected()->withConsent()->create();
    }

    private function guard(): WhatsappSendGuard
    {
        return app(WhatsappSendGuard::class);
    }

    private function setting(string $key, int $value): void
    {
        app(Settings::class)->set($key, $value);
    }

    /**
     * An outbound row with its recipient row(s), as the outbound service writes them.
     *
     * @param  list<string>|null  $participants  recipients of a group mutation
     */
    private function outbound(
        string $toJid,
        string $status = 'sent',
        ?CarbonImmutable $at = null,
        bool $isNonContact = false,
        string $kind = 'text',
        ?array $participants = null,
    ): WhatsappOutboundMessage {
        $at ??= CarbonImmutable::now();

        $factory = WhatsappOutboundMessage::factory()->forSession($this->session);
        $factory = $status === 'blocked' ? $factory->blocked() : $factory->{$status}();

        $outbound = $factory->create([
            'kind' => $kind,
            'to_jid' => $toJid,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        foreach ($participants ?? [$toJid] as $jid) {
            WhatsappOutboundRecipient::query()->create([
                'whatsapp_outbound_message_id' => $outbound->id,
                'whatsapp_session_id' => $this->session->id,
                'jid' => $jid,
                'is_non_contact' => $isNonContact,
                'created_at' => $at,
            ]);
        }

        return $outbound;
    }

    private function nonContact(int $number): string
    {
        return '55219'.str_pad((string) $number, 8, '0', STR_PAD_LEFT).'@s.whatsapp.net';
    }

    private function blockedAudits(string $reason): int
    {
        return AuditLog::query()
            ->where('audit_action_id', AuditAction::idFor(AuditAction::WHATSAPP_SEND_BLOCKED))
            ->where('properties->reason', $reason)
            ->count();
    }

    /**
     * @return WhatsappOperationException the exception thrown by the closure
     */
    private function expectBlock(callable $evaluation, string $reason): WhatsappOperationException
    {
        try {
            $evaluation();
        } catch (WhatsappOperationException $exception) {
            $this->assertSame($reason, $exception->reason);

            return $exception;
        }

        $this->fail("Expected the guard to block with [{$reason}].");
    }

    public function test_send_beyond_per_minute_limit_is_rate_limited_with_retry_after(): void
    {
        $this->setting('whatsapp.max_sends_per_minute', 3);
        $this->outbound(self::CONTACT, at: CarbonImmutable::now()->subSeconds(40));
        $this->outbound($this->nonContact(1), at: CarbonImmutable::now()->subSeconds(20));
        $this->outbound($this->nonContact(2), 'queued', CarbonImmutable::now()->subSeconds(5));

        $exception = $this->expectBlock(
            fn () => $this->guard()->assertCanSend($this->session, self::CONTACT),
            WhatsappOperationException::RATE_LIMITED,
        );

        $this->assertNotNull($exception->retryAfter);
        $this->assertGreaterThan(0, $exception->retryAfter);
        $this->assertLessThanOrEqual(20, $exception->retryAfter);
        $this->assertStringContainsString('retry_after=', $exception->getMessage());
        $this->assertSame(1, $this->blockedAudits(WhatsappOperationException::RATE_LIMITED));
        $this->assertNoWuzapiSend();
    }

    public function test_failed_and_blocked_sends_do_not_count(): void
    {
        $this->setting('whatsapp.max_sends_per_minute', 2);
        $this->outbound(self::CONTACT);
        $this->outbound(self::CONTACT, 'failed');
        $this->outbound(self::CONTACT, 'blocked');
        $this->outbound(self::CONTACT, 'blocked');

        $this->assertFalse($this->guard()->assertCanSend($this->session, self::CONTACT));
        $this->assertSame(1, $this->guard()->unitsToday($this->session));
    }

    public function test_own_outbound_is_excluded_from_the_count(): void
    {
        $this->setting('whatsapp.max_sends_per_minute', 2);
        $this->outbound(self::CONTACT);
        $own = $this->outbound(self::CONTACT, 'queued');

        $this->expectBlock(
            fn () => $this->guard()->assertCanSend($this->session, self::CONTACT),
            WhatsappOperationException::RATE_LIMITED,
        );

        $this->assertFalse($this->guard()->assertCanSend($this->session, self::CONTACT, $own->id));
    }

    public function test_new_limit_applies_to_the_next_evaluation(): void
    {
        $this->setting('whatsapp.max_sends_per_minute', 3);
        $this->outbound(self::CONTACT);
        $this->outbound(self::CONTACT);

        $this->guard()->assertCanSend($this->session, self::CONTACT);

        $this->setting('whatsapp.max_sends_per_minute', 2);

        $this->expectBlock(
            fn () => $this->guard()->assertCanSend($this->session, self::CONTACT),
            WhatsappOperationException::RATE_LIMITED,
        );
    }

    public function test_recipient_limit_blocks_same_jid_and_lets_another_pass(): void
    {
        $this->setting('whatsapp.max_sends_per_recipient_per_hour', 3);

        foreach ([50, 30, 10] as $minutesAgo) {
            $this->outbound(self::CONTACT, at: CarbonImmutable::now()->subMinutes($minutesAgo));
        }

        $this->outbound(self::CONTACT, at: CarbonImmutable::now()->subMinutes(61));

        $this->expectBlock(
            fn () => $this->guard()->assertCanSend($this->session, self::CONTACT),
            WhatsappOperationException::RECIPIENT_RATE_LIMITED,
        );

        WhatsappMessage::factory()->create([
            'whatsapp_session_id' => $this->session->id,
            'user_id' => $this->session->user_id,
            'chat_jid' => '5511977776666@s.whatsapp.net',
            'sender_jid' => '5511977776666@s.whatsapp.net',
            'from_me' => false,
        ]);

        $this->assertFalse($this->guard()->assertCanSend($this->session, '5511977776666@s.whatsapp.net'));
        $this->assertSame(1, $this->blockedAudits(WhatsappOperationException::RECIPIENT_RATE_LIMITED));
    }

    public function test_daily_cap_turns_over_at_midnight_in_sao_paulo(): void
    {
        $this->setting('whatsapp.daily_send_cap', 50);
        $this->setting('whatsapp.max_sends_per_recipient_per_hour', 50);

        $evening = CarbonImmutable::parse('2026-11-07 23:50:00', 'America/Sao_Paulo')->utc();
        $this->travelTo($evening);

        for ($i = 0; $i < 50; $i++) {
            $this->outbound('55119666'.str_pad((string) $i, 5, '0', STR_PAD_LEFT).'@s.whatsapp.net', at: $evening);
        }

        $this->travelTo(CarbonImmutable::parse('2026-11-07 23:59:00', 'America/Sao_Paulo'));
        $this->assertSame(50, $this->guard()->unitsToday($this->session));

        $this->expectBlock(
            fn () => $this->guard()->assertCanSend($this->session, self::CONTACT),
            WhatsappOperationException::DAILY_CAP_REACHED,
        );

        $this->travelTo(CarbonImmutable::parse('2026-11-08 00:01:00', 'America/Sao_Paulo'));
        $this->assertSame(0, $this->guard()->unitsToday($this->session));
        $this->assertSame(1, $this->blockedAudits(WhatsappOperationException::DAILY_CAP_REACHED));

        $this->assertFalse($this->guard()->assertCanSend($this->session, self::CONTACT));
    }

    public function test_mass_send_to_non_contacts_is_blocked_after_k_distinct(): void
    {
        $this->setting('whatsapp.max_non_contact_recipients', 2);
        $at = CarbonImmutable::now()->subMinutes(10);
        $this->outbound($this->nonContact(1), at: $at, isNonContact: true);
        $this->outbound($this->nonContact(2), at: $at, isNonContact: true);
        $this->outbound($this->nonContact(3), at: CarbonImmutable::now()->subMinutes(61), isNonContact: true);

        $this->expectBlock(
            fn () => $this->guard()->assertCanSend($this->session, $this->nonContact(4)),
            WhatsappOperationException::MASS_SEND_BLOCKED,
        );

        $this->assertTrue($this->guard()->assertCanSend($this->session, $this->nonContact(1)), 'already counted JID costs nothing');
        $this->assertFalse($this->guard()->assertCanSend($this->session, self::CONTACT), 'contacts are not affected');

        WhatsappMessage::factory()->create([
            'whatsapp_session_id' => $this->session->id,
            'user_id' => $this->session->user_id,
            'chat_jid' => $this->nonContact(5),
            'sender_jid' => $this->nonContact(5),
            'from_me' => false,
        ]);

        $this->assertFalse($this->guard()->assertCanSend($this->session, $this->nonContact(5)), 'received message makes it a contact');
        $this->assertSame(1, $this->blockedAudits(WhatsappOperationException::MASS_SEND_BLOCKED));
        $this->assertWuzapiCalled('GET', '/user/contacts', 1);
    }

    public function test_message_only_sent_by_me_does_not_make_a_contact(): void
    {
        WhatsappMessage::factory()->create([
            'whatsapp_session_id' => $this->session->id,
            'user_id' => $this->session->user_id,
            'chat_jid' => $this->nonContact(9),
            'sender_jid' => null,
            'from_me' => true,
        ]);

        $this->assertTrue($this->guard()->assertCanSend($this->session, $this->nonContact(9)));
    }

    public function test_group_participants_count_in_daily_cap_and_non_contacts_but_not_per_minute(): void
    {
        $this->setting('whatsapp.max_sends_per_minute', 2);
        $this->setting('whatsapp.max_non_contact_recipients', 5);
        $participants = [$this->nonContact(1), $this->nonContact(2), $this->nonContact(3)];

        $flags = $this->guard()->assertCanAddParticipants($this->session, $participants);

        $this->assertSame(array_fill_keys($participants, true), $flags);

        $this->outbound('120363000000000000@g.us', 'queued', isNonContact: true, kind: 'group_create', participants: $participants);
        $this->outbound(self::CONTACT);

        $this->assertSame(4, $this->guard()->unitsToday($this->session));
        $this->assertFalse($this->guard()->assertCanSend($this->session, self::CONTACT), 'participants do not count per minute');

        $this->expectBlock(
            fn () => $this->guard()->assertCanAddParticipants($this->session, [$this->nonContact(4), $this->nonContact(5), $this->nonContact(6)]),
            WhatsappOperationException::MASS_SEND_BLOCKED,
        );

        $this->assertSame(
            [self::CONTACT => false, $this->nonContact(1) => true],
            $this->guard()->assertCanAddParticipants($this->session, [self::CONTACT, $this->nonContact(1)]),
        );
    }

    public function test_participants_exceeding_daily_cap_reject_the_whole_operation(): void
    {
        $this->setting('whatsapp.daily_send_cap', 50);
        $this->setting('whatsapp.max_sends_per_recipient_per_hour', 50);

        for ($i = 0; $i < 48; $i++) {
            $this->outbound(self::CONTACT, at: CarbonImmutable::now()->subMinutes(5));
        }

        $this->expectBlock(
            fn () => $this->guard()->assertCanAddParticipants($this->session, [self::CONTACT, $this->nonContact(1), $this->nonContact(2)]),
            WhatsappOperationException::DAILY_CAP_REACHED,
        );

        $this->assertCount(2, $this->guard()->assertCanAddParticipants($this->session, [self::CONTACT, $this->nonContact(1)]));
    }

    public function test_kill_switch_and_suspension_block_every_write(): void
    {
        $this->setting('whatsapp.kill_switch', 1);

        $this->expectBlock(
            fn () => $this->guard()->assertCanSend($this->session, self::CONTACT),
            WhatsappOperationException::KILL_SWITCH_ACTIVE,
        );
        $this->expectBlock(
            fn () => $this->guard()->assertCanAddParticipants($this->session, [self::CONTACT]),
            WhatsappOperationException::KILL_SWITCH_ACTIVE,
        );

        $this->setting('whatsapp.kill_switch', 0);
        $this->session->update(['suspended_at' => now()]);

        $this->expectBlock(
            fn () => $this->guard()->assertCanSend($this->session, self::CONTACT),
            WhatsappOperationException::SESSION_SUSPENDED,
        );

        $this->assertSame(2, $this->blockedAudits(WhatsappOperationException::KILL_SWITCH_ACTIVE));
        $this->assertSame(1, $this->blockedAudits(WhatsappOperationException::SESSION_SUSPENDED));
        $this->assertWuzapiCalled('GET', '/user/contacts', 0);
    }

    public function test_block_audit_carries_reason_session_and_recipient(): void
    {
        $this->setting('whatsapp.kill_switch', 1);

        $this->expectBlock(
            fn () => $this->guard()->assertCanSend($this->session, self::CONTACT),
            WhatsappOperationException::KILL_SWITCH_ACTIVE,
        );

        $audit = AuditLog::query()->sole();

        $this->assertSame(AuditAction::idFor(AuditAction::WHATSAPP_SEND_BLOCKED), $audit->audit_action_id);
        $this->assertSame($this->session->user_id, $audit->user_id);
        $this->assertEquals([
            'reason' => WhatsappOperationException::KILL_SWITCH_ACTIVE,
            'session_id' => $this->session->id,
            'recipient' => self::CONTACT,
        ], $audit->properties);
    }

    public function test_contacts_are_cached_for_five_minutes(): void
    {
        $this->guard()->assertCanSend($this->session, self::CONTACT);
        $this->guard()->assertCanSend($this->session, $this->nonContact(1));

        $this->assertWuzapiCalled('GET', '/user/contacts', 1);

        $key = Cache::getPrefix().WhatsappContactDirectory::cacheKey($this->session);
        $ttl = Cache::getStore()->connection()->ttl($key);
        $this->assertGreaterThan(290, $ttl);
        $this->assertLessThanOrEqual(WhatsappContactDirectory::CONTACTS_TTL_SECONDS, $ttl);

        app(WhatsappContactDirectory::class)->forget($this->session);
        $this->guard()->assertCanSend($this->session, self::CONTACT);

        $this->assertWuzapiCalled('GET', '/user/contacts', 2);
    }
}
