<?php

namespace App\Services;

use App\Exceptions\WhatsappOperationException;
use App\Exceptions\WuzapiRequestException;
use App\Jobs\DeliverWhatsappOutbound;
use App\Models\Approval;
use App\Models\AuditAction;
use App\Models\NotificationType;
use App\Models\User;
use App\Models\WhatsappConsent;
use App\Models\WhatsappMessage;
use App\Models\WhatsappOutboundMessage;
use App\Models\WhatsappOutboundRecipient;
use App\Models\WhatsappOutboundStatus;
use App\Models\WhatsappSchedule;
use App\Models\WhatsappSession;
use App\Support\AuditLogger;
use App\Support\Settings;
use App\Support\WhatsappMediaFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use InvalidArgumentException;

/**
 * The single exit path of WhatsApp messages (RF-19, RNF-04). `enqueue()`
 * checks consent, session and brakes under the session guard lock and
 * persists a `queued` row; `deliverNext()` (run by `DeliverWhatsappOutbound`)
 * delivers one row at a time per session under the delivery lock (RNF-05),
 * re-evaluating everything at delivery (RF-28), signalling presence
 * (RF-24) and spacing deliveries by `min_send_interval + jitter` (RF-23).
 * Every row ends in exactly one of `sent`, `failed` or `blocked` and is
 * never retried.
 */
class WhatsappOutboundService
{
    /**
     * Kinds that are messages to one recipient (group mutations go through `WhatsappGroupService`).
     *
     * @var list<string>
     */
    public const array MESSAGE_KINDS = ['text', 'image', 'video', 'audio', 'document', 'location'];

    /**
     * Seconds the guard lock (enqueue) may be held; covers the media download.
     */
    public const int GUARD_LOCK_SECONDS = 120;

    /**
     * Seconds `enqueue()` waits for the guard lock.
     */
    public const int GUARD_LOCK_WAIT_SECONDS = 30;

    /**
     * Seconds the delivery lock may be held; covers presence, download and send.
     */
    public const int DELIVERY_LOCK_SECONDS = 300;

    /**
     * Delay before retrying when another worker holds the delivery lock.
     */
    public const int BUSY_RETRY_SECONDS = 5;

    /**
     * Milliseconds of "typing" per character of text (RF-24).
     */
    public const int TYPING_MS_PER_CHARACTER = 50;

    public function __construct(
        private readonly WhatsappConsentService $consents,
        private readonly WhatsappSendGuard $guard,
        private readonly WhatsappMediaFetcher $media,
        private readonly WuzapiClient $wuzapi,
        private readonly NotificationService $notifications,
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Validate and persist a send as `queued`, then schedule its delivery
     * after commit (RF-19). Checks follow D8: consent and scopes, session,
     * suspension, kill switch, `connected`, brakes and, for media, the
     * download (RF-44) — all before any wuzapi call.
     *
     * @param  array<string, mixed>  $payload  `text`; `url` (+ `caption`, `filename`); or `latitude`, `longitude` (+ `name`)
     * @param  array<string, mixed>  $meta
     * @return array{outbound_id: int, status: string}
     *
     * @throws WhatsappOperationException
     */
    public function enqueue(
        User $user,
        string $kind,
        string $toJid,
        array $payload,
        ?Approval $approval = null,
        ?WhatsappSchedule $schedule = null,
        array $meta = [],
    ): array {
        if (! in_array($kind, self::MESSAGE_KINDS, true)) {
            throw new InvalidArgumentException("Unknown WhatsApp message kind [{$kind}].");
        }

        $this->consents->assertAllows($user, self::scopesFor($kind));

        $sessionId = WhatsappSession::query()->where('user_id', $user->id)->value('id');

        if ($sessionId === null) {
            throw new WhatsappOperationException(WhatsappOperationException::NOT_CONNECTED);
        }

        $lock = Cache::lock(self::guardLockKey((int) $sessionId), self::GUARD_LOCK_SECONDS);

        try {
            $outbound = $lock->block(self::GUARD_LOCK_WAIT_SECONDS, function () use ($sessionId, $user, $kind, $toJid, $payload, $approval, $schedule, $meta): WhatsappOutboundMessage {
                $session = WhatsappSession::query()->with('status')->findOrFail($sessionId);
                $session->setRelation('user', $user);

                $this->assertConnectedUnlessGuarded($session);

                $isNonContact = $this->guard->assertCanSend($session, $toJid);

                if (in_array($kind, WhatsappMediaFetcher::KINDS, true)) {
                    $this->media->fetch((string) ($payload['url'] ?? ''), $kind);
                }

                return DB::transaction(function () use ($session, $kind, $toJid, $payload, $approval, $schedule, $meta, $isNonContact): WhatsappOutboundMessage {
                    $outbound = WhatsappOutboundMessage::query()->create([
                        'user_id' => $session->user_id,
                        'whatsapp_session_id' => $session->id,
                        'whatsapp_outbound_status_id' => WhatsappOutboundStatus::idFor(WhatsappOutboundStatus::QUEUED),
                        'kind' => $kind,
                        'to_jid' => $toJid,
                        'payload' => $payload,
                        'meta' => $meta === [] ? null : $meta,
                        'whatsapp_schedule_id' => $schedule?->id,
                        'approval_id' => $approval?->id,
                    ]);

                    WhatsappOutboundRecipient::query()->create([
                        'whatsapp_outbound_message_id' => $outbound->id,
                        'whatsapp_session_id' => $session->id,
                        'jid' => $toJid,
                        'is_non_contact' => $isNonContact,
                        'created_at' => now(),
                    ]);

                    DeliverWhatsappOutbound::dispatch($session->id)->afterCommit();

                    return $outbound;
                });
            });
        } catch (LockTimeoutException) {
            throw WhatsappOperationException::rateLimited(self::GUARD_LOCK_WAIT_SECONDS);
        }

        return ['outbound_id' => $outbound->id, 'status' => WhatsappOutboundStatus::QUEUED];
    }

    /**
     * Deliver the oldest `queued` row of the session (RF-23, RNF-05). Rows
     * blocked at delivery do not consume the interval, so the next one is
     * tried right away; the loop stops after the first upstream attempt.
     *
     * @return CarbonImmutable|null When the next delivery may start, or null when nothing is left.
     */
    public function deliverNext(int $sessionId): ?CarbonImmutable
    {
        $lock = Cache::lock(self::deliveryLockKey($sessionId), self::DELIVERY_LOCK_SECONDS);

        if (! $lock->get()) {
            return CarbonImmutable::now()->addSeconds(self::BUSY_RETRY_SECONDS);
        }

        try {
            while (true) {
                $session = WhatsappSession::query()->with(['status', 'user'])->find($sessionId);

                if ($session === null) {
                    return null;
                }

                if ($session->next_delivery_at !== null && $session->next_delivery_at->isFuture()) {
                    return $this->hasQueued($session) ? CarbonImmutable::instance($session->next_delivery_at) : null;
                }

                $outbound = WhatsappOutboundMessage::query()
                    ->where('whatsapp_session_id', $session->id)
                    ->where('whatsapp_outbound_status_id', WhatsappOutboundStatus::idFor(WhatsappOutboundStatus::QUEUED))
                    ->oldest('id')
                    ->first();

                if ($outbound === null) {
                    return null;
                }

                if ($this->deliver($session, $outbound)) {
                    $session->refresh();

                    return $this->hasQueued($session) ? CarbonImmutable::instance($session->next_delivery_at) : null;
                }
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Consent scopes a message kind requires (CT-30).
     *
     * @return list<string>
     */
    public static function scopesFor(string $kind): array
    {
        return in_array($kind, WhatsappMediaFetcher::KINDS, true)
            ? [WhatsappConsent::SCOPE_SEND, WhatsappConsent::SCOPE_MEDIA]
            : [WhatsappConsent::SCOPE_SEND];
    }

    public static function guardLockKey(int $sessionId): string
    {
        return "whatsapp:session:{$sessionId}:guard";
    }

    public static function deliveryLockKey(int $sessionId): string
    {
        return "whatsapp:session:{$sessionId}:deliver";
    }

    /**
     * Re-evaluate and send one row.
     *
     * @return bool Whether wuzapi was asked to send (the interval applies).
     */
    private function deliver(WhatsappSession $session, WhatsappOutboundMessage $outbound): bool
    {
        $outbound->forceFill(['delivery_started_at' => now()])->save();

        try {
            $this->consents->assertAllows($session->user, self::scopesFor($outbound->kind));
            $this->assertConnectedUnlessGuarded($session);
        } catch (WhatsappOperationException $exception) {
            $this->block($session, $outbound, $exception->reason, audited: false);

            return false;
        }

        try {
            $this->guard->assertCanSend($session, $outbound->to_jid, $outbound->id);
        } catch (WhatsappOperationException $exception) {
            $this->block($session, $outbound, $exception->reason, audited: true);

            return false;
        }

        $dataUri = null;

        if (in_array($outbound->kind, WhatsappMediaFetcher::KINDS, true)) {
            try {
                $dataUri = $this->media->fetch((string) ($outbound->payload['url'] ?? ''), $outbound->kind)['data_uri'];
            } catch (WhatsappOperationException $exception) {
                $this->block($session, $outbound, $exception->reason, audited: false);

                return false;
            }
        }

        $client = $this->wuzapi->forSession($session);
        $presence = $this->startPresence($client, $outbound);

        try {
            $upstreamId = $this->send($client, $outbound, $dataUri);
        } catch (WuzapiRequestException $exception) {
            $this->scheduleNextDelivery($session);
            $this->stopPresence($client, $outbound, $presence);

            Log::warning('WhatsApp send failed upstream.', [
                'whatsapp_outbound_message_id' => $outbound->id,
                'status' => $exception->status,
                'detail' => $exception->upstreamDetail,
            ]);

            $this->finish($session, $outbound, WhatsappOutboundStatus::FAILED, WhatsappOperationException::UPSTREAM_ERROR);

            return true;
        }

        $this->scheduleNextDelivery($session);
        $this->stopPresence($client, $outbound, $presence);

        $outbound->forceFill(['upstream_id' => $upstreamId, 'sent_at' => now()]);

        WhatsappMessage::query()
            ->where('whatsapp_session_id', $session->id)
            ->where('upstream_id', $upstreamId)
            ->where('from_me', true)
            ->whereNull('whatsapp_outbound_message_id')
            ->update(['whatsapp_outbound_message_id' => $outbound->id]);

        $this->audit->log(AuditAction::WHATSAPP_MESSAGE_SENT, $session->user, $outbound, [
            'outbound_id' => $outbound->id,
            'session_id' => $session->id,
            'kind' => $outbound->kind,
            'recipient' => $outbound->to_jid,
            'upstream_id' => $upstreamId,
        ]);

        $this->finish($session, $outbound, WhatsappOutboundStatus::SENT);

        return true;
    }

    /**
     * Suspension and the kill switch are left to the guard, which audits
     * them in D8 order; only a session that is otherwise writable but not
     * `connected` fails here (RF-17).
     *
     * @throws WhatsappOperationException
     */
    private function assertConnectedUnlessGuarded(WhatsappSession $session): void
    {
        if ($session->isSuspended() || $this->settings->whatsappKillSwitchActive()) {
            return;
        }

        if (! $session->isConnected()) {
            throw new WhatsappOperationException(WhatsappOperationException::NOT_CONNECTED);
        }
    }

    /**
     * "Typing" for text (length × 50 ms within the typing range) and
     * "recording" for audio (RF-24); other kinds send without presence.
     * Presence is best effort: its failure does not stop the send.
     *
     * @return bool Whether a presence was started.
     */
    private function startPresence(WuzapiClient $client, WhatsappOutboundMessage $outbound): bool
    {
        if (! in_array($outbound->kind, ['text', 'audio'], true)) {
            return false;
        }

        $minMs = $this->settings->whatsappTypingMinMs();
        $maxMs = $this->settings->whatsappTypingMaxMs();

        if ($outbound->kind === 'text') {
            $length = mb_strlen((string) ($outbound->payload['text'] ?? ''));
            $durationMs = max($minMs, min($maxMs, $length * self::TYPING_MS_PER_CHARACTER));
            $media = '';
        } else {
            $durationMs = $minMs;
            $media = 'audio';
        }

        try {
            $client->chatPresence($outbound->to_jid, 'composing', $media);
        } catch (WuzapiRequestException $exception) {
            Log::info('WhatsApp presence could not be set.', [
                'whatsapp_outbound_message_id' => $outbound->id,
                'status' => $exception->status,
            ]);
        }

        Sleep::for($durationMs)->milliseconds();

        return true;
    }

    private function stopPresence(WuzapiClient $client, WhatsappOutboundMessage $outbound, bool $started): void
    {
        if (! $started) {
            return;
        }

        try {
            $client->chatPresence($outbound->to_jid, 'paused', $outbound->kind === 'audio' ? 'audio' : '');
        } catch (WuzapiRequestException $exception) {
            Log::info('WhatsApp presence could not be cleared.', [
                'whatsapp_outbound_message_id' => $outbound->id,
                'status' => $exception->status,
            ]);
        }
    }

    /**
     * @throws WuzapiRequestException
     */
    private function send(WuzapiClient $client, WhatsappOutboundMessage $outbound, ?string $dataUri): string
    {
        $payload = $outbound->payload ?? [];
        $to = $outbound->to_jid;
        $caption = isset($payload['caption']) ? (string) $payload['caption'] : null;

        return match ($outbound->kind) {
            'text' => $client->sendText($to, (string) ($payload['text'] ?? '')),
            'image' => $client->sendImage($to, (string) $dataUri, $caption),
            'video' => $client->sendVideo($to, (string) $dataUri, $caption),
            'audio' => $client->sendAudio($to, (string) $dataUri),
            'document' => $client->sendDocument($to, (string) $dataUri, (string) ($payload['filename'] ?? 'documento'), self::mimetypeOf((string) $dataUri)),
            'location' => $client->sendLocation($to, (float) $payload['latitude'], (float) $payload['longitude'], isset($payload['name']) ? (string) $payload['name'] : null),
        };
    }

    /**
     * Space the next delivery from the end of this one: `min_send_interval`
     * plus a uniform jitter (RF-23, C-06). Rounded up to the second: the
     * column has no fractional seconds and truncating would shorten the gap.
     */
    private function scheduleNextDelivery(WhatsappSession $session): void
    {
        $seconds = $this->settings->whatsappMinSendIntervalSeconds()
            + random_int($this->settings->whatsappJitterMinSeconds(), $this->settings->whatsappJitterMaxSeconds());

        $session->forceFill(['next_delivery_at' => now()->addSeconds($seconds)->ceilSecond()])->save();
    }

    /**
     * Blocked at delivery (RF-28): the guard already audited its own codes.
     */
    private function block(WhatsappSession $session, WhatsappOutboundMessage $outbound, string $reason, bool $audited): void
    {
        if (! $audited) {
            $this->audit->log(AuditAction::WHATSAPP_SEND_BLOCKED, $session->user, $session, [
                'reason' => $reason,
                'session_id' => $session->id,
                'recipient' => $outbound->to_jid,
            ]);
        }

        $this->finish($session, $outbound, WhatsappOutboundStatus::BLOCKED, $reason);
    }

    /**
     * Terminal status, the owner notification for `failed`/`blocked`
     * (D10) and, for a schedule occurrence, its result audit (D9).
     */
    private function finish(WhatsappSession $session, WhatsappOutboundMessage $outbound, string $statusSlug, ?string $reason = null): void
    {
        $outbound->forceFill([
            'whatsapp_outbound_status_id' => WhatsappOutboundStatus::idFor($statusSlug),
            'reason' => $reason,
            'finished_at' => now(),
        ])->save();

        $fromSchedule = $outbound->whatsapp_schedule_id !== null;

        if ($reason !== null) {
            $this->notifications->notify(
                $session->user,
                $fromSchedule ? NotificationType::WHATSAPP_SCHEDULE_RUN_FAILED : NotificationType::WHATSAPP_SEND_FAILED,
                __($fromSchedule ? 'whatsapp.notifications.schedule_run_failed' : "whatsapp.notifications.send_{$statusSlug}"),
                WhatsappOperationException::translate($reason),
                array_filter([
                    'whatsapp_outbound_message_id' => $outbound->id,
                    'whatsapp_schedule_id' => $outbound->whatsapp_schedule_id,
                    'reason' => $reason,
                ], fn (mixed $value): bool => $value !== null),
            );
        }

        if ($fromSchedule) {
            $this->audit->log(AuditAction::WHATSAPP_SCHEDULE_EXECUTED, $session->user, $outbound, [
                'schedule_id' => $outbound->whatsapp_schedule_id,
                'outbound_id' => $outbound->id,
                'result' => $statusSlug,
                'reason' => $reason,
                'discarded' => (int) ($outbound->meta['discarded_occurrences'] ?? 0),
            ]);
        }
    }

    private function hasQueued(WhatsappSession $session): bool
    {
        return WhatsappOutboundMessage::query()
            ->where('whatsapp_session_id', $session->id)
            ->where('whatsapp_outbound_status_id', WhatsappOutboundStatus::idFor(WhatsappOutboundStatus::QUEUED))
            ->exists();
    }

    private static function mimetypeOf(string $dataUri): ?string
    {
        return preg_match('/^data:([^;,]+)[;,]/', $dataUri, $matches) === 1 ? $matches[1] : null;
    }
}
