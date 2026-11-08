<?php

namespace App\Exceptions;

use App\Support\UntrustedContent;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Failure of a WhatsApp operation identified by a CT-31 code. The message is
 * `"<code>: <pt-BR text>"` (plus missing scopes or `retry_after=N`) so tool
 * executions persist the code; it never carries tokens or keys. The same
 * codes are stored as the `reason` of `failed`/`blocked` outbound messages.
 */
class WhatsappOperationException extends RuntimeException
{
    public const string CONSENT_REQUIRED = 'whatsapp_consent_required';

    public const string NOT_CONNECTED = 'whatsapp_not_connected';

    public const string SESSION_SUSPENDED = 'whatsapp_session_suspended';

    public const string RATE_LIMITED = 'whatsapp_rate_limited';

    public const string RECIPIENT_RATE_LIMITED = 'whatsapp_recipient_rate_limited';

    public const string DAILY_CAP_REACHED = 'whatsapp_daily_cap_reached';

    public const string MASS_SEND_BLOCKED = 'whatsapp_mass_send_blocked';

    public const string KILL_SWITCH_ACTIVE = 'whatsapp_kill_switch_active';

    public const string MEDIA_INVALID = 'whatsapp_media_invalid';

    public const string UPSTREAM_ERROR = 'whatsapp_upstream_error';

    /**
     * @var list<string>
     */
    public const array CODES = [
        self::CONSENT_REQUIRED,
        self::NOT_CONNECTED,
        self::SESSION_SUSPENDED,
        self::RATE_LIMITED,
        self::RECIPIENT_RATE_LIMITED,
        self::DAILY_CAP_REACHED,
        self::MASS_SEND_BLOCKED,
        self::KILL_SWITCH_ACTIVE,
        self::MEDIA_INVALID,
        self::UPSTREAM_ERROR,
    ];

    /**
     * @param  list<string>  $missingScopes  scopes the operation lacks (`whatsapp_consent_required`)
     * @param  int|null  $retryAfter  seconds until a new attempt may pass (`whatsapp_rate_limited`)
     * @param  string|null  $upstreamDetail  already redacted wuzapi reason (`whatsapp_upstream_error`), neutralized as untrusted data (RF-18)
     */
    public function __construct(
        public readonly string $reason,
        public readonly array $missingScopes = [],
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
        public readonly ?string $upstreamDetail = null,
    ) {
        if (! in_array($reason, self::CODES, true)) {
            throw new InvalidArgumentException("Unknown WhatsApp operation code [{$reason}].");
        }

        parent::__construct(self::messageFor($reason, $missingScopes, $retryAfter, $upstreamDetail), 0, $previous);
    }

    /**
     * @param  list<string>  $missingScopes
     */
    public static function consentRequired(array $missingScopes): self
    {
        return new self(self::CONSENT_REQUIRED, missingScopes: array_values($missingScopes));
    }

    public static function rateLimited(int $retryAfter): self
    {
        return new self(self::RATE_LIMITED, retryAfter: max(1, $retryAfter));
    }

    /**
     * wuzapi failed: the detail is already redacted by `WuzapiClient`.
     */
    public static function upstreamError(?string $upstreamDetail = null): self
    {
        return new self(self::UPSTREAM_ERROR, upstreamDetail: blank($upstreamDetail) ? null : UntrustedContent::neutralize($upstreamDetail));
    }

    /**
     * The pt-BR text of a code, without the code prefix (notifications).
     */
    public static function translate(string $reason): string
    {
        return __('whatsapp.errors.'.$reason);
    }

    /**
     * @param  list<string>  $missingScopes
     */
    private static function messageFor(string $reason, array $missingScopes, ?int $retryAfter, ?string $upstreamDetail): string
    {
        $message = $reason.': '.self::translate($reason);

        if ($missingScopes !== []) {
            $message .= ' '.__('whatsapp.missing_scopes', ['scopes' => implode(', ', $missingScopes)]);
        }

        if ($retryAfter !== null) {
            $message .= ' '.__('whatsapp.retry_after', ['seconds' => $retryAfter])." (retry_after={$retryAfter})";
        }

        if ($upstreamDetail !== null) {
            $message .= ' '.__('whatsapp.upstream_detail', ['detail' => UntrustedContent::neutralize($upstreamDetail)]);
        }

        return $message;
    }
}
