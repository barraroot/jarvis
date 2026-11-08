<?php

namespace App\Services;

use App\Exceptions\WhatsappOperationException;
use App\Exceptions\WuzapiRequestException;
use App\Models\Approval;
use App\Models\WhatsappOutboundMessage;
use App\Models\WhatsappOutboundRecipient;
use App\Models\WhatsappOutboundStatus;
use App\Models\WhatsappSession;
use App\Support\WhatsappMediaFetcher;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The single path of WhatsApp group mutations (RNF-04). Adding participants
 * (group creation or `add`) goes through `assertCanAddParticipants` under the
 * session guard lock and, once wuzapi accepted it, writes a `sent`
 * `group_create|group_participants` row with one recipient row per
 * participant, so they consume the daily cap and the non-contact limit but
 * not the per-minute or per-recipient limits (RF-29). An upstream failure is
 * recorded as `failed` (which never counts). Every other mutation —
 * update, remove/promote/demote, leave — checks the kill switch and the
 * suspension first (RF-26, RF-41).
 */
class WhatsappGroupService
{
    /**
     * Participant actions accepted by `POST /group/updateparticipants`.
     *
     * @var list<string>
     */
    public const array PARTICIPANT_ACTIONS = ['add', 'remove', 'promote', 'demote'];

    public function __construct(
        private readonly WhatsappSendGuard $guard,
        private readonly WhatsappMediaFetcher $media,
        private readonly WuzapiClient $wuzapi,
    ) {}

    /**
     * Create a group with its initial participants (RF-29).
     *
     * @param  list<string>  $participants  phones or user JIDs
     * @return array{jid: string, name: string, participants: list<string>}
     *
     * @throws WhatsappOperationException
     */
    public function create(WhatsappSession $session, string $name, array $participants, ?Approval $approval = null): array
    {
        $jids = self::normalize($participants);

        $group = $this->withParticipantBudget(
            $session,
            $jids,
            fn (WuzapiClient $client): array => $client->groupCreate($name, $jids),
            fn (array $result): array => [
                'kind' => 'group_create',
                'to_jid' => is_string($result['JID'] ?? null) ? $result['JID'] : '',
                'payload' => ['name' => $name, 'participants' => $jids],
            ],
            ['kind' => 'group_create', 'to_jid' => '', 'payload' => ['name' => $name, 'participants' => $jids]],
            $approval,
        );

        return [
            'jid' => is_string($group['JID'] ?? null) ? $group['JID'] : '',
            'name' => is_string($group['Name'] ?? null) ? $group['Name'] : $name,
            'participants' => $jids,
        ];
    }

    /**
     * Add, remove, promote or demote participants. Only `add` consumes the
     * brakes (RF-29); the other actions only check the kill switch and the
     * suspension.
     *
     * @param  list<string>  $participants  phones or user JIDs
     * @return list<string> The normalized participant JIDs.
     *
     * @throws WhatsappOperationException
     */
    public function updateParticipants(WhatsappSession $session, string $groupJid, string $action, array $participants, ?Approval $approval = null): array
    {
        $jids = self::normalize($participants);
        $payload = ['group_jid' => $groupJid, 'action' => $action, 'participants' => $jids];

        if ($action === 'add') {
            $this->withParticipantBudget(
                $session,
                $jids,
                fn (WuzapiClient $client): array => $client->groupUpdateParticipants($groupJid, $jids, 'add'),
                fn (): array => ['kind' => 'group_participants', 'to_jid' => $groupJid, 'payload' => $payload],
                ['kind' => 'group_participants', 'to_jid' => $groupJid, 'payload' => $payload],
                $approval,
            );

            return $jids;
        }

        $this->guard->assertWritable($session, $groupJid);
        $this->call($session, fn (WuzapiClient $client): array => $client->groupUpdateParticipants($groupJid, $jids, $action));

        return $jids;
    }

    /**
     * Change the group's name, description, photo (an `https` URL fetched by
     * the server, RF-44), announce-only and locked settings.
     *
     * @param  array{name?: string, topic?: string, photo_url?: string, announce?: bool, locked?: bool}  $changes
     * @return list<string> The settings that were changed, in the order applied.
     *
     * @throws WhatsappOperationException
     */
    public function update(WhatsappSession $session, string $groupJid, array $changes): array
    {
        $this->guard->assertWritable($session, $groupJid);

        $photo = isset($changes['photo_url'])
            ? $this->media->fetch($changes['photo_url'], 'image')['data_uri']
            : null;

        $updated = [];

        if (isset($changes['name'])) {
            $this->call($session, fn (WuzapiClient $client) => $client->groupName($groupJid, $changes['name']));
            $updated[] = 'name';
        }

        if (isset($changes['topic'])) {
            $this->call($session, fn (WuzapiClient $client) => $client->groupTopic($groupJid, $changes['topic']));
            $updated[] = 'topic';
        }

        if ($photo !== null) {
            $this->call($session, fn (WuzapiClient $client) => $client->groupPhoto($groupJid, $photo));
            $updated[] = 'photo';
        }

        if (isset($changes['announce'])) {
            $this->call($session, fn (WuzapiClient $client) => $client->groupAnnounce($groupJid, $changes['announce']));
            $updated[] = 'announce';
        }

        if (isset($changes['locked'])) {
            $this->call($session, fn (WuzapiClient $client) => $client->groupLocked($groupJid, $changes['locked']));
            $updated[] = 'locked';
        }

        return $updated;
    }

    /**
     * @throws WhatsappOperationException
     */
    public function leave(WhatsappSession $session, string $groupJid): void
    {
        $this->guard->assertWritable($session, $groupJid);
        $this->call($session, fn (WuzapiClient $client) => $client->groupLeave($groupJid));
    }

    /**
     * Check the participant budget, call wuzapi and record the outcome, all
     * under the session guard lock so concurrent calls cannot overshoot.
     *
     * @param  list<string>  $jids
     * @param  Closure(WuzapiClient): array<array-key, mixed>  $upstream
     * @param  Closure(array<array-key, mixed>): array{kind: string, to_jid: string, payload: array<string, mixed>}  $sentRow
     * @param  array{kind: string, to_jid: string, payload: array<string, mixed>}  $failedRow
     * @return array<array-key, mixed> The upstream answer.
     *
     * @throws WhatsappOperationException
     */
    private function withParticipantBudget(WhatsappSession $session, array $jids, Closure $upstream, Closure $sentRow, array $failedRow, ?Approval $approval): array
    {
        $lock = Cache::lock(WhatsappOutboundService::guardLockKey($session->id), WhatsappOutboundService::GUARD_LOCK_SECONDS);

        try {
            return $lock->block(WhatsappOutboundService::GUARD_LOCK_WAIT_SECONDS, function () use ($session, $jids, $upstream, $sentRow, $failedRow, $approval): array {
                $nonContacts = $this->guard->assertCanAddParticipants($session, $jids);

                try {
                    $result = $upstream($this->wuzapi->forSession($session));
                } catch (WuzapiRequestException $exception) {
                    $this->record($session, $failedRow, WhatsappOutboundStatus::FAILED, $nonContacts, $approval);

                    throw WhatsappOperationException::upstreamError($exception->upstreamDetail);
                }

                $this->record($session, $sentRow($result), WhatsappOutboundStatus::SENT, $nonContacts, $approval);

                return $result;
            });
        } catch (LockTimeoutException) {
            throw WhatsappOperationException::rateLimited(WhatsappOutboundService::GUARD_LOCK_WAIT_SECONDS);
        }
    }

    /**
     * @param  array{kind: string, to_jid: string, payload: array<string, mixed>}  $row
     * @param  array<string, bool>  $nonContacts
     */
    private function record(WhatsappSession $session, array $row, string $statusSlug, array $nonContacts, ?Approval $approval): void
    {
        DB::transaction(function () use ($session, $row, $statusSlug, $nonContacts, $approval): void {
            $failed = $statusSlug === WhatsappOutboundStatus::FAILED;

            $outbound = WhatsappOutboundMessage::query()->create([
                'user_id' => $session->user_id,
                'whatsapp_session_id' => $session->id,
                'whatsapp_outbound_status_id' => WhatsappOutboundStatus::idFor($statusSlug),
                'kind' => $row['kind'],
                'to_jid' => $row['to_jid'],
                'payload' => $row['payload'],
                'reason' => $failed ? WhatsappOperationException::UPSTREAM_ERROR : null,
                'approval_id' => $approval?->id,
                'delivery_started_at' => now(),
                'sent_at' => $failed ? null : now(),
                'finished_at' => now(),
            ]);

            WhatsappOutboundRecipient::query()->insert(array_map(fn (string $jid): array => [
                'whatsapp_outbound_message_id' => $outbound->id,
                'whatsapp_session_id' => $session->id,
                'jid' => $jid,
                'is_non_contact' => $nonContacts[$jid] ?? true,
                'created_at' => now(),
            ], array_keys($nonContacts)));
        });
    }

    /**
     * @template TResult
     *
     * @param  Closure(WuzapiClient): TResult  $call
     * @return TResult
     *
     * @throws WhatsappOperationException
     */
    private function call(WhatsappSession $session, Closure $call): mixed
    {
        try {
            return $call($this->wuzapi->forSession($session));
        } catch (WuzapiRequestException $exception) {
            throw WhatsappOperationException::upstreamError($exception->upstreamDetail);
        }
    }

    /**
     * @param  list<string>  $participants
     * @return list<string>
     */
    private static function normalize(array $participants): array
    {
        return array_values(array_unique(array_map(WhatsappContactDirectory::normalizeJid(...), $participants)));
    }
}
