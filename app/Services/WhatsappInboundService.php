<?php

namespace App\Services;

use App\Models\WhatsappConsent;
use App\Models\WhatsappMessage;
use App\Models\WhatsappOutboundMessage;
use App\Models\WhatsappSession;
use Carbon\CarbonImmutable;

/**
 * Stores messages delivered by the webhook (RF-36): only while the owner
 * holds a valid consent with the `leitura` scope (RF-11), deduplicated by
 * `(session, upstream_id)`, media as metadata only (C-09), and an
 * `IsFromMe` echo linked to the JARVIS send with the same `upstream_id`.
 * Storing never triggers an agent, task, tool or send (RF-37).
 */
class WhatsappInboundService
{
    /**
     * @param  array{upstream_id: string, chat_jid: string, sender_jid: string|null, from_me: bool, type: string, text: string|null, media: array<string, mixed>|null, sent_at: CarbonImmutable}  $message
     * @return bool Whether a new row was stored.
     */
    public function store(WhatsappSession $session, array $message): bool
    {
        if (! $this->allowsReading($session)) {
            return false;
        }

        $now = now();

        return WhatsappMessage::query()->insertOrIgnore([
            'whatsapp_session_id' => $session->id,
            'user_id' => $session->user_id,
            'upstream_id' => $message['upstream_id'],
            'chat_jid' => $message['chat_jid'],
            'sender_jid' => $message['sender_jid'],
            'from_me' => $message['from_me'],
            'type' => $message['type'],
            'text' => $message['text'],
            'media' => $message['media'] === null ? null : json_encode($message['media'], JSON_THROW_ON_ERROR),
            'whatsapp_outbound_message_id' => $message['from_me'] ? $this->echoedOutboundId($session, $message['upstream_id']) : null,
            'sent_at' => $message['sent_at'],
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1;
    }

    private function allowsReading(WhatsappSession $session): bool
    {
        $consent = WhatsappConsent::query()->where('user_id', $session->user_id)->first();

        return $consent?->allows([WhatsappConsent::SCOPE_READ]) ?? false;
    }

    private function echoedOutboundId(WhatsappSession $session, string $upstreamId): ?int
    {
        $id = WhatsappOutboundMessage::query()
            ->where('whatsapp_session_id', $session->id)
            ->where('upstream_id', $upstreamId)
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
