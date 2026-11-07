<?php

namespace App\Services;

use App\Exceptions\WhatsappOperationException;
use App\Models\AuditAction;
use App\Models\WhatsappOutboundMessage;
use App\Models\WhatsappOutboundRecipient;
use App\Models\WhatsappOutboundStatus;
use App\Models\WhatsappSchedule;
use App\Models\WhatsappSession;
use App\Support\AuditLogger;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The anti-ban brakes every outbound operation goes through (RF-20–RF-29),
 * evaluated at enqueue time and again at delivery (RF-28). Counts are SQL
 * queries over `sent` + `queued` outbound rows of the session, excluding the
 * row under evaluation (C-04, D3); `failed`/`blocked` never count. Limits are
 * read from Settings on every evaluation (RF-42). Each block writes one
 * `whatsapp_send_blocked` audit and throws `WhatsappOperationException`.
 */
class WhatsappSendGuard
{
    /**
     * Outbound kinds that are group mutations: their participants count only
     * towards the daily cap and the non-contact limit (RF-29).
     *
     * @var list<string>
     */
    public const array GROUP_KINDS = ['group_create', 'group_participants'];

    public function __construct(
        private readonly Settings $settings,
        private readonly WhatsappContactDirectory $contacts,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Check a single-recipient message send.
     *
     * @return bool Whether the recipient is a non-contact (stored on the recipient row).
     *
     * @throws WhatsappOperationException
     */
    public function assertCanSend(WhatsappSession $session, string $toJid, ?int $excludeOutboundId = null): bool
    {
        $this->assertWritable($session, $toJid);

        $this->assertWithinPerMinute($session, $toJid, $excludeOutboundId);

        $sameRecipient = $this->messageSends($session, $excludeOutboundId)
            ->where('to_jid', $toJid)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($sameRecipient >= $this->settings->whatsappMaxSendsPerRecipientPerHour()) {
            $this->block($session, WhatsappOperationException::RECIPIENT_RATE_LIMITED, $toJid);
        }

        if ($this->unitsToday($session, $excludeOutboundId) + 1 > $this->settings->whatsappDailySendCap()) {
            $this->block($session, WhatsappOperationException::DAILY_CAP_REACHED, $toJid);
        }

        $isNonContact = $this->contacts->isNonContact($session, $toJid);

        if ($isNonContact && $this->newNonContacts($session, [$toJid], $excludeOutboundId) > 0) {
            $this->block($session, WhatsappOperationException::MASS_SEND_BLOCKED, $toJid);
        }

        return $isNonContact;
    }

    /**
     * Check adding group participants (group creation or `add`): each one
     * costs a daily-cap unit and, when a non-contact, a non-contact unit; the
     * per-minute and per-recipient limits do not apply (RF-29). Exceeding a
     * limit rejects the whole operation.
     *
     * @param  list<string>  $jids
     * @return array<string, bool> Non-contact flag by participant JID.
     *
     * @throws WhatsappOperationException
     */
    public function assertCanAddParticipants(WhatsappSession $session, array $jids, ?int $excludeOutboundId = null): array
    {
        $jids = array_values(array_unique($jids));
        $recipient = implode(',', $jids);

        $this->assertWritable($session, $recipient);

        if ($this->unitsToday($session, $excludeOutboundId) + count($jids) > $this->settings->whatsappDailySendCap()) {
            $this->block($session, WhatsappOperationException::DAILY_CAP_REACHED, $recipient);
        }

        $flags = [];

        foreach ($jids as $jid) {
            $flags[$jid] = $this->contacts->isNonContact($session, $jid);
        }

        $nonContacts = array_keys(array_filter($flags));

        if ($nonContacts !== [] && $this->newNonContacts($session, $nonContacts, $excludeOutboundId) > 0) {
            $this->block($session, WhatsappOperationException::MASS_SEND_BLOCKED, $recipient);
        }

        return $flags;
    }

    /**
     * Daily-cap units used today: recipient rows of `sent`/`queued` outbound
     * messages since 00:00 in `America/Sao_Paulo` (RF-22, C-02, CT-03).
     */
    public function unitsToday(WhatsappSession $session, ?int $excludeOutboundId = null): int
    {
        return $this->countedRecipients($session, $excludeOutboundId)
            ->where('whatsapp_outbound_recipients.created_at', '>=', self::startOfToday())
            ->count();
    }

    /**
     * 00:00 of the current civil day in `America/Sao_Paulo`, in UTC.
     */
    public static function startOfToday(): CarbonImmutable
    {
        return CarbonImmutable::now(WhatsappSchedule::TIMEZONE)->startOfDay()->utc();
    }

    /**
     * Kill switch, then suspension (RF-26, RF-41).
     *
     * @throws WhatsappOperationException
     */
    private function assertWritable(WhatsappSession $session, string $recipient): void
    {
        if ($this->settings->whatsappKillSwitchActive()) {
            $this->block($session, WhatsappOperationException::KILL_SWITCH_ACTIVE, $recipient);
        }

        if ($session->isSuspended()) {
            $this->block($session, WhatsappOperationException::SESSION_SUSPENDED, $recipient);
        }
    }

    /**
     * @throws WhatsappOperationException
     */
    private function assertWithinPerMinute(WhatsappSession $session, string $toJid, ?int $excludeOutboundId): void
    {
        $windowStart = now()->subMinute();

        $recent = $this->messageSends($session, $excludeOutboundId)
            ->where('created_at', '>=', $windowStart)
            ->selectRaw('count(*) as sends, min(created_at) as oldest')
            ->toBase()
            ->first();

        if ((int) $recent->sends < $this->settings->whatsappMaxSendsPerMinute()) {
            return;
        }

        $oldestAge = (int) CarbonImmutable::parse($recent->oldest)->diffInSeconds(now(), true);

        $this->block($session, WhatsappOperationException::RATE_LIMITED, $toJid, max(1, 60 - $oldestAge));
    }

    /**
     * How many of the given non-contact JIDs are new in the window and would
     * exceed `max_non_contact_recipients`; a JID already counted in the window
     * costs nothing (RF-25).
     *
     * @param  list<string>  $jids
     */
    private function newNonContacts(WhatsappSession $session, array $jids, ?int $excludeOutboundId): int
    {
        $counted = $this->countedRecipients($session, $excludeOutboundId)
            ->where('whatsapp_outbound_recipients.is_non_contact', true)
            ->where('whatsapp_outbound_recipients.created_at', '>=', now()->subMinutes($this->settings->whatsappNonContactWindowMinutes()))
            ->distinct()
            ->pluck('whatsapp_outbound_recipients.jid')
            ->all();

        $new = array_diff(array_unique($jids), $counted);

        return max(0, count($counted) + count($new) - $this->settings->whatsappMaxNonContactRecipients());
    }

    /**
     * Message sends (not group mutations) that count: `sent` + `queued`, own row excluded.
     *
     * @return Builder<WhatsappOutboundMessage>
     */
    private function messageSends(WhatsappSession $session, ?int $excludeOutboundId): Builder
    {
        return WhatsappOutboundMessage::query()
            ->where('whatsapp_session_id', $session->id)
            ->whereIn('whatsapp_outbound_status_id', self::countedStatusIds())
            ->whereNotIn('kind', self::GROUP_KINDS)
            ->when($excludeOutboundId !== null, fn (Builder $query) => $query->whereKeyNot($excludeOutboundId));
    }

    /**
     * Recipient rows of `sent` + `queued` outbound messages, own row excluded.
     *
     * @return Builder<WhatsappOutboundRecipient>
     */
    private function countedRecipients(WhatsappSession $session, ?int $excludeOutboundId): Builder
    {
        return WhatsappOutboundRecipient::query()
            ->join('whatsapp_outbound_messages', 'whatsapp_outbound_messages.id', '=', 'whatsapp_outbound_recipients.whatsapp_outbound_message_id')
            ->where('whatsapp_outbound_recipients.whatsapp_session_id', $session->id)
            ->whereIn('whatsapp_outbound_messages.whatsapp_outbound_status_id', self::countedStatusIds())
            ->when($excludeOutboundId !== null, fn (Builder $query) => $query->where('whatsapp_outbound_messages.id', '!=', $excludeOutboundId));
    }

    /**
     * @return list<int>
     */
    private static function countedStatusIds(): array
    {
        return [
            WhatsappOutboundStatus::idFor(WhatsappOutboundStatus::SENT),
            WhatsappOutboundStatus::idFor(WhatsappOutboundStatus::QUEUED),
        ];
    }

    /**
     * @throws WhatsappOperationException
     */
    private function block(WhatsappSession $session, string $reason, string $recipient, ?int $retryAfter = null): never
    {
        $session->loadMissing('user');

        $properties = [
            'reason' => $reason,
            'session_id' => $session->id,
            'recipient' => $recipient,
        ];

        if ($retryAfter !== null) {
            $properties['retry_after'] = $retryAfter;
        }

        $this->audit->log(AuditAction::WHATSAPP_SEND_BLOCKED, $session->user, $session, $properties);

        throw $reason === WhatsappOperationException::RATE_LIMITED
            ? WhatsappOperationException::rateLimited((int) $retryAfter)
            : new WhatsappOperationException($reason);
    }
}
