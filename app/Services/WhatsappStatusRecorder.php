<?php

namespace App\Services;

use App\Events\Whatsapp\SessionStatusChanged;
use App\Models\ConnectorStatus;
use App\Models\NotificationType;
use App\Models\WhatsappSession;

/**
 * The only writer of a WhatsApp session status (RF-06), modelled on
 * `ConnectorStatusService`: persists the status, error and timestamps;
 * broadcasts CT-10 only on a transition; entering `error` notifies the owner
 * (`connector_error`).
 */
class WhatsappStatusRecorder
{
    /**
     * Max length of `whatsapp_sessions.last_error`.
     */
    public const int ERROR_MAX_LENGTH = 500;

    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * @param  string|null  $error  pt-BR message without secrets; kept only in `error`
     * @param  bool  $checked  whether this outcome comes from a status check (`last_checked_at`)
     * @param  string|null  $phoneMasked  masked phone of the paired device, when known
     * @return bool Whether the status changed.
     */
    public function record(
        WhatsappSession $session,
        string $statusSlug,
        ?string $error = null,
        bool $checked = false,
        ?string $phoneMasked = null,
    ): bool {
        $previous = $session->statusSlug();
        $entersConnected = $statusSlug === ConnectorStatus::CONNECTED && $previous !== ConnectorStatus::CONNECTED;

        $attributes = [
            'connector_status_id' => ConnectorStatus::idFor($statusSlug),
            'last_error' => $statusSlug === ConnectorStatus::ERROR ? $this->sanitize($session, $error) : null,
        ];

        if ($checked) {
            $attributes['last_checked_at'] = now();
        }

        if ($entersConnected) {
            $attributes['connected_at'] = now();
        }

        if ($phoneMasked !== null) {
            $attributes['phone_masked'] = $phoneMasked;
        }

        $session->forceFill($attributes)->save();
        $session->load('status');

        if ($previous === $statusSlug) {
            return false;
        }

        SessionStatusChanged::dispatch($session, $previous);

        if ($statusSlug === ConnectorStatus::ERROR) {
            $session->loadMissing('user');

            $this->notifications->notify(
                $session->user,
                NotificationType::CONNECTOR_ERROR,
                'WhatsApp com erro',
                $session->last_error,
                ['whatsapp_session_id' => $session->id],
            );
        }

        return true;
    }

    /**
     * Broadcast CT-10 after a suspension change, with the status unchanged (RF-41).
     */
    public function broadcastSuspension(WhatsappSession $session): void
    {
        $session->loadMissing('status');

        SessionStatusChanged::dispatch($session, $session->statusSlug());
    }

    private function sanitize(WhatsappSession $session, ?string $error): ?string
    {
        if (blank($error)) {
            return null;
        }

        $secrets = array_filter([
            (string) $session->token,
            (string) $session->hmac_key,
            (string) $session->webhook_key,
            (string) config('services.wuzapi.admin_token'),
        ], fn (string $secret): bool => $secret !== '');

        return mb_strimwidth(str_replace($secrets, '[redacted]', trim($error)), 0, self::ERROR_MAX_LENGTH, '…');
    }
}
