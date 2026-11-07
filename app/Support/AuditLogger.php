<?php

namespace App\Support;

use App\Models\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Writes append-only audit entries (US-12.3), never persisting secrets.
 */
class AuditLogger
{
    /**
     * Property keys (case-insensitive, matched at any nesting level) that are stripped before persisting.
     *
     * @var list<string>
     */
    public const array SECRET_KEYS = ['api_key', 'credential', 'password', 'token', 'hmac_key', 'webhook_key'];

    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>  $properties
     */
    public function log(string $actionSlug, ?User $actor, ?Model $subject = null, array $properties = []): AuditLog
    {
        return AuditLog::query()->create([
            'audit_action_id' => AuditAction::idFor($actionSlug),
            'user_id' => $actor?->getKey(),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'properties' => $properties === [] ? null : self::sanitize($properties),
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
            'created_at' => now(),
        ]);
    }

    /**
     * Recursively drop every key that looks like a secret.
     *
     * @param  array<array-key, mixed>  $properties
     * @return array<array-key, mixed>
     */
    public static function sanitize(array $properties): array
    {
        $clean = [];

        foreach ($properties as $key => $value) {
            if (is_string($key) && self::isSecretKey($key)) {
                continue;
            }

            $clean[$key] = is_array($value) ? self::sanitize($value) : $value;
        }

        return $clean;
    }

    private static function isSecretKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::SECRET_KEYS as $secret) {
            if ($normalized === $secret || str_ends_with($normalized, '_'.$secret) || str_starts_with($normalized, $secret.'_')) {
                return true;
            }
        }

        return false;
    }
}
