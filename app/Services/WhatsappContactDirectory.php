<?php

namespace App\Services;

use App\Exceptions\WuzapiRequestException;
use App\Models\WhatsappMessage;
use App\Models\WhatsappSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Tells contacts from non-contacts for the mass-send guard (RF-25, C-07, D12):
 * a non-contact is a JID absent from the session's `/user/contacts` AND
 * without any received (`from_me = false`) stored message in the session.
 * The address book is cached per session for 5 minutes.
 */
class WhatsappContactDirectory
{
    public const int CONTACTS_TTL_SECONDS = 300;

    public const string USER_SERVER = '@s.whatsapp.net';

    public function __construct(private readonly WuzapiClient $client) {}

    public static function cacheKey(WhatsappSession $session): string
    {
        return "whatsapp:{$session->id}:contacts";
    }

    /**
     * A bare phone number becomes a user JID; anything with a server is kept.
     */
    public static function normalizeJid(string $jid): string
    {
        $jid = trim($jid);

        if (str_contains($jid, '@')) {
            return $jid;
        }

        return preg_replace('/\D+/', '', $jid).self::USER_SERVER;
    }

    public function isNonContact(WhatsappSession $session, string $jid): bool
    {
        $jid = self::normalizeJid($jid);

        if ($this->hasReceivedMessageFrom($session, $jid)) {
            return false;
        }

        return ! in_array($jid, $this->contacts($session), true);
    }

    /**
     * JIDs of the session's address book. An upstream failure is not cached
     * and yields an empty book, so every unknown JID counts as a non-contact
     * (the conservative side of RF-25).
     *
     * @return list<string>
     */
    public function contacts(WhatsappSession $session): array
    {
        $cached = Cache::get(self::cacheKey($session));

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $book = $this->client->forSession($session)->contacts();
        } catch (WuzapiRequestException $exception) {
            Log::warning('WhatsApp contacts unavailable; treating unknown JIDs as non-contacts.', [
                'whatsapp_session_id' => $session->id,
                'status' => $exception->status,
            ]);

            return [];
        }

        $jids = array_values(array_map('strval', array_keys($book)));

        Cache::put(self::cacheKey($session), $jids, self::CONTACTS_TTL_SECONDS);

        return $jids;
    }

    public function forget(WhatsappSession $session): void
    {
        Cache::forget(self::cacheKey($session));
    }

    private function hasReceivedMessageFrom(WhatsappSession $session, string $jid): bool
    {
        return WhatsappMessage::query()
            ->where('whatsapp_session_id', $session->id)
            ->where('from_me', false)
            ->where(fn ($query) => $query->where('chat_jid', $jid)->orWhere('sender_jid', $jid))
            ->exists();
    }
}
