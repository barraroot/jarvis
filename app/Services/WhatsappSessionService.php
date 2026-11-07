<?php

namespace App\Services;

use App\Exceptions\ApprovalNotDecidableException;
use App\Exceptions\WhatsappOperationException;
use App\Exceptions\WhatsappSessionStateException;
use App\Exceptions\WhatsappUpstreamUnavailableException;
use App\Exceptions\WuzapiRequestException;
use App\Models\Approval;
use App\Models\ApprovalStatus;
use App\Models\AuditAction;
use App\Models\ConnectorStatus;
use App\Models\NotificationType;
use App\Models\User;
use App\Models\WhatsappConsent;
use App\Models\WhatsappMessage;
use App\Models\WhatsappSchedule;
use App\Models\WhatsappScheduleStatus;
use App\Models\WhatsappSession;
use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Lifecycle of the owner's WhatsApp session: provisioning the dedicated
 * wuzapi user (RF-01–RF-04), pairing (RF-05), consent revocation (RF-11),
 * removal (RF-08) and admin suspension (RF-41). Tokens, HMAC keys, webhook
 * keys, QR codes and pairing codes never reach audits, logs or broadcasts
 * (RNF-01, RNF-10).
 */
class WhatsappSessionService
{
    /**
     * Route of the session webhook receiver (CT-09).
     */
    public const string WEBHOOK_ROUTE = 'api.webhooks.wuzapi';

    /**
     * Path of CT-09, used while the route is not registered.
     */
    public const string WEBHOOK_PATH = '/api/v1/webhooks/wuzapi/';

    /**
     * Statuses from which a session may be paired (RF-05, C-08).
     *
     * @var list<string>
     */
    public const array PAIRABLE_STATUSES = [ConnectorStatus::PENDING, ConnectorStatus::DISCONNECTED];

    public function __construct(
        private readonly WuzapiClient $wuzapi,
        private readonly WhatsappConsentService $consents,
        private readonly WhatsappStatusRecorder $recorder,
        private readonly ApprovalService $approvals,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Create the owner's session, or return the existing one (RF-03). A
     * session left in `error` is retried (D17): without a wuzapi user it is
     * created again, otherwise only the HMAC configuration is redone.
     *
     * @return array{0: WhatsappSession, 1: bool} The session and whether it was created now.
     *
     * @throws WhatsappOperationException without a valid consent (RF-02)
     * @throws WhatsappUpstreamUnavailableException when wuzapi fails (RF-04)
     */
    public function provision(User $user): array
    {
        $this->consents->assertAllows($user, []);

        [$session, $created] = DB::transaction(function () use ($user): array {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $existing = WhatsappSession::query()->with('status')->where('user_id', $user->id)->first();

            if ($existing !== null) {
                return [$existing, false];
            }

            $session = WhatsappSession::query()->create([
                'user_id' => $user->id,
                'token' => Str::random(48),
                'hmac_key' => Str::random(48),
                'webhook_key' => Str::random(48),
                'connector_status_id' => ConnectorStatus::idFor(ConnectorStatus::PENDING),
            ]);

            return [$session->load('status'), true];
        });

        if ($created || $session->statusSlug() === ConnectorStatus::ERROR) {
            $this->provisionUpstream($session, $user);
        }

        $session->setRelation('user', $user);

        return [$session, $created];
    }

    /**
     * Start the connection and return the QR code as a data URI. Never
     * persisted, logged nor broadcast (RNF-10).
     *
     * @throws WhatsappSessionStateException
     * @throws WhatsappUpstreamUnavailableException
     */
    public function requestQr(WhatsappSession $session): string
    {
        $this->assertPairable($session);

        try {
            $client = $this->wuzapi->forSession($session);
            $client->connect();
            $qr = $client->qr();
        } catch (WuzapiRequestException $exception) {
            throw new WhatsappUpstreamUnavailableException($exception->upstreamDetail);
        }

        if ($qr === null) {
            throw new WhatsappUpstreamUnavailableException;
        }

        return $qr;
    }

    /**
     * Start the connection and return the linking code for the phone. Never
     * persisted, logged nor broadcast (RNF-10).
     *
     * @throws WhatsappSessionStateException
     * @throws WhatsappUpstreamUnavailableException
     */
    public function pairPhone(WhatsappSession $session, string $phone): string
    {
        $this->assertPairable($session);

        try {
            $client = $this->wuzapi->forSession($session);
            $client->connect();

            return $client->pairPhone($phone);
        } catch (WuzapiRequestException $exception) {
            throw new WhatsappUpstreamUnavailableException($exception->upstreamDetail);
        }
    }

    /**
     * Revoke the consent and undo its effects (RF-11, D16): unlink the device,
     * mark the session `disconnected`, purge stored messages, deny pending
     * `whatsapp.*` approvals and pause active schedules. The local part always
     * completes, even when wuzapi is down. Idempotent.
     */
    public function revokeConsent(User $user): void
    {
        $hasActiveConsent = WhatsappConsent::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->exists();

        if (! $hasActiveConsent) {
            return;
        }

        $this->consents->markRevoked($user);

        $session = WhatsappSession::query()->with('status')->where('user_id', $user->id)->first();
        $logoutError = null;

        if ($session !== null && $session->wuzapi_user_id !== null) {
            try {
                $this->wuzapi->forSession($session)->logout();
            } catch (WuzapiRequestException $exception) {
                $logoutError = $exception->upstreamDetail;

                Log::warning('WhatsApp logout failed while revoking consent.', [
                    'whatsapp_session_id' => $session->id,
                    'status' => $exception->status,
                    'detail' => $exception->upstreamDetail,
                ]);
            }
        }

        if ($session !== null) {
            $this->recorder->record($session, ConnectorStatus::DISCONNECTED);

            if ($logoutError !== null) {
                $session->forceFill([
                    'last_error' => mb_strimwidth($logoutError, 0, WhatsappStatusRecorder::ERROR_MAX_LENGTH, '…'),
                ])->save();
            }
        }

        [$deletedMessages, $pausedSchedules] = DB::transaction(fn (): array => [
            WhatsappMessage::query()->where('user_id', $user->id)->delete(),
            WhatsappSchedule::query()
                ->where('user_id', $user->id)
                ->where('whatsapp_schedule_status_id', WhatsappScheduleStatus::idFor(WhatsappScheduleStatus::ACTIVE))
                ->update([
                    'whatsapp_schedule_status_id' => WhatsappScheduleStatus::idFor(WhatsappScheduleStatus::PAUSED),
                    'updated_at' => now(),
                ]),
        ]);

        $deniedApprovals = $this->denyPendingApprovals($user, __('whatsapp.approvals.consent_revoked'));

        $this->audit->log(AuditAction::WHATSAPP_CONSENT_REVOKED, $user, $session, [
            'whatsapp_session_id' => $session?->id,
            'deleted_messages' => $deletedMessages,
            'paused_schedules' => $pausedSchedules,
            'denied_approvals' => $deniedApprovals,
            'logout_failed' => $logoutError !== null,
        ]);
    }

    /**
     * Remove the WhatsApp of the owner (RF-08): full removal upstream, then
     * the session row (messages, outbound messages, recipients and schedules
     * go by cascade) and the pending `whatsapp.*` approvals.
     *
     * @return bool Whether a session existed.
     *
     * @throws WhatsappUpstreamUnavailableException when wuzapi fails (nothing is removed)
     */
    public function remove(User $user): bool
    {
        $session = WhatsappSession::query()->where('user_id', $user->id)->first();

        if ($session === null) {
            return false;
        }

        if ($session->wuzapi_user_id !== null) {
            try {
                $this->wuzapi->deleteUserFull($session->wuzapi_user_id);
            } catch (WuzapiRequestException $exception) {
                if ($exception->status !== 404) {
                    throw new WhatsappUpstreamUnavailableException($exception->upstreamDetail);
                }
            }
        }

        $deniedApprovals = $this->denyPendingApprovals($user, __('whatsapp.approvals.session_removed'));

        DB::transaction(fn () => $session->delete());

        $this->audit->log(AuditAction::WHATSAPP_SESSION_REMOVED, $user, $session, [
            'whatsapp_session_id' => $session->id,
            'wuzapi_user_id' => $session->wuzapi_user_id,
            'denied_approvals' => $deniedApprovals,
        ]);

        return true;
    }

    /**
     * Admin suspension (RF-41): disconnect upstream, block every `whatsapp.*`
     * tool of the owner, notify the owner and audit. The suspension holds even
     * when wuzapi cannot be reached. Idempotent.
     */
    public function suspend(WhatsappSession $session, User $admin): WhatsappSession
    {
        if ($session->isSuspended()) {
            return $session;
        }

        $disconnectFailed = false;

        try {
            $this->wuzapi->forSession($session)->disconnect();
        } catch (WuzapiRequestException $exception) {
            $disconnectFailed = true;

            Log::warning('WhatsApp disconnect failed while suspending the session.', [
                'whatsapp_session_id' => $session->id,
                'status' => $exception->status,
                'detail' => $exception->upstreamDetail,
            ]);
        }

        $session->forceFill(['suspended_at' => now()])->save();

        $this->recorder->broadcastSuspension($session);

        $session->loadMissing('user');

        $this->notifications->notify(
            $session->user,
            NotificationType::WHATSAPP_SESSION_SUSPENDED,
            'WhatsApp suspenso',
            WhatsappOperationException::translate(WhatsappOperationException::SESSION_SUSPENDED),
            ['whatsapp_session_id' => $session->id],
        );

        $this->audit->log(AuditAction::WHATSAPP_SESSION_SUSPENDED, $admin, $session, [
            'whatsapp_session_id' => $session->id,
            'owner_id' => $session->user_id,
            'disconnect_failed' => $disconnectFailed,
        ]);

        return $session;
    }

    /**
     * Lift the suspension (RF-41). `last_checked_at` is cleared so the next
     * health check reconciles the status. Idempotent.
     */
    public function resume(WhatsappSession $session, User $admin): WhatsappSession
    {
        if (! $session->isSuspended()) {
            return $session;
        }

        $session->forceFill(['suspended_at' => null, 'last_checked_at' => null])->save();

        $this->recorder->broadcastSuspension($session);

        $this->audit->log(AuditAction::WHATSAPP_SESSION_RESUMED, $admin, $session, [
            'whatsapp_session_id' => $session->id,
            'owner_id' => $session->user_id,
        ]);

        return $session;
    }

    /**
     * The CT-09 URL wuzapi posts the session events to.
     */
    public function webhookUrl(WhatsappSession $session): string
    {
        $base = rtrim((string) (config('services.wuzapi.webhook_base_url') ?: config('app.url')), '/');

        $path = Route::has(self::WEBHOOK_ROUTE)
            ? route(self::WEBHOOK_ROUTE, ['webhook_key' => $session->webhook_key], false)
            : self::WEBHOOK_PATH.$session->webhook_key;

        return $base.$path;
    }

    /**
     * Create the wuzapi user (when missing) and configure the HMAC key, then
     * settle on `pending`; any failure settles on `error` (RF-04).
     *
     * @throws WhatsappUpstreamUnavailableException
     */
    private function provisionUpstream(WhatsappSession $session, User $user): void
    {
        try {
            if ($session->wuzapi_user_id === null) {
                $wuzapiUserId = $this->wuzapi->createUser(
                    "jarvis-user-{$user->id}",
                    (string) $session->token,
                    $this->webhookUrl($session),
                );

                $session->forceFill(['wuzapi_user_id' => $wuzapiUserId])->save();
            }

            $this->wuzapi->forSession($session)->configureHmac((string) $session->hmac_key);
        } catch (WuzapiRequestException $exception) {
            $this->recorder->record($session, ConnectorStatus::ERROR, $exception->upstreamDetail);

            throw new WhatsappUpstreamUnavailableException($exception->upstreamDetail);
        }

        $this->recorder->record($session, ConnectorStatus::PENDING);

        $this->audit->log(AuditAction::WHATSAPP_SESSION_PROVISIONED, $user, $session, [
            'whatsapp_session_id' => $session->id,
            'wuzapi_user_id' => $session->wuzapi_user_id,
        ]);
    }

    /**
     * @throws WhatsappSessionStateException
     */
    private function assertPairable(WhatsappSession $session): void
    {
        $session->loadMissing('status');

        if ($session->isSuspended() || ! in_array($session->statusSlug(), self::PAIRABLE_STATUSES, true)) {
            throw new WhatsappSessionStateException;
        }
    }

    /**
     * Deny every pending `whatsapp.*` approval of the owner, with the owner
     * as decider (RF-08, RF-11); approvals past their window are expired.
     *
     * @return int How many approvals were closed.
     */
    private function denyPendingApprovals(User $user, string $reason): int
    {
        $approvals = Approval::query()
            ->where('user_id', $user->id)
            ->where('approval_status_id', ApprovalStatus::idFor(ApprovalStatus::PENDING))
            ->whereHas('tool', fn (Builder $query) => $query->where('identifier', 'like', 'whatsapp.%'))
            ->with(['tool', 'task'])
            ->get();

        foreach ($approvals as $approval) {
            try {
                $approval->isExpired()
                    ? $this->approvals->expire($approval)
                    : $this->approvals->deny($approval, $user, $reason);
            } catch (ApprovalNotDecidableException) {
                continue;
            }
        }

        return $approvals->count();
    }
}
