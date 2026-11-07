<?php

namespace App\Services;

use App\Exceptions\WhatsappOperationException;
use App\Models\AuditAction;
use App\Models\User;
use App\Models\WhatsappConsent;
use App\Support\AuditLogger;
use Illuminate\Validation\ValidationException;

/**
 * WhatsApp consent (RF-09–RF-13): accepting the current text with a scope
 * subset, and the check every `whatsapp.*` tool runs before calling wuzapi.
 * Revocation orchestration (logout, purge, approvals) lives in the session
 * service; here only the row is marked.
 */
class WhatsappConsentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Accept the current consent text; the scopes replace any previous set (RF-13).
     *
     * @param  list<string>  $scopes
     *
     * @throws ValidationException
     */
    public function grant(User $user, int $version, array $scopes): WhatsappConsent
    {
        if ($version !== WhatsappConsent::CURRENT_VERSION) {
            throw ValidationException::withMessages(['version' => __('whatsapp.consent.version_mismatch')]);
        }

        if (! $this->areValidScopes($scopes)) {
            throw ValidationException::withMessages(['scopes' => __('whatsapp.consent.invalid_scopes')]);
        }

        $orderedScopes = array_values(array_intersect(WhatsappConsent::SCOPES, $scopes));

        $consent = WhatsappConsent::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'version' => $version,
                'scopes' => $orderedScopes,
                'consented_at' => now(),
                'revoked_at' => null,
            ],
        );

        $this->audit->log(AuditAction::WHATSAPP_CONSENT_GRANTED, $user, $consent, [
            'version' => $version,
            'scopes' => $orderedScopes,
        ]);

        $user->setRelation('whatsappConsent', $consent);

        return $consent;
    }

    public function current(User $user): ?WhatsappConsent
    {
        return WhatsappConsent::query()->where('user_id', $user->id)->first();
    }

    /**
     * Present, not revoked and given for the current text (RF-10, RF-12).
     */
    public function isValid(User $user): bool
    {
        return $this->current($user)?->isValid() ?? false;
    }

    /**
     * Fail with `whatsapp_consent_required` listing the missing scopes; an
     * absent, revoked or outdated consent lacks every required scope (RF-10).
     *
     * @param  list<string>  $requiredScopes
     *
     * @throws WhatsappOperationException
     */
    public function assertAllows(User $user, array $requiredScopes): void
    {
        $consent = $this->current($user);

        if ($consent === null || ! $consent->isValid()) {
            throw WhatsappOperationException::consentRequired($requiredScopes);
        }

        $missingScopes = $consent->missingScopes($requiredScopes);

        if ($missingScopes !== []) {
            throw WhatsappOperationException::consentRequired($missingScopes);
        }
    }

    /**
     * Mark the consent revoked (RF-11); the caller orchestrates the side effects.
     */
    public function markRevoked(User $user): void
    {
        WhatsappConsent::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    /**
     * 1 to 4 known scopes, no repetition (CT-01).
     *
     * @param  array<array-key, mixed>  $scopes
     */
    private function areValidScopes(array $scopes): bool
    {
        if ($scopes === [] || count($scopes) > count(WhatsappConsent::SCOPES)) {
            return false;
        }

        foreach ($scopes as $scope) {
            if (! is_string($scope) || ! in_array($scope, WhatsappConsent::SCOPES, true)) {
                return false;
            }
        }

        return count(array_unique($scopes)) === count($scopes);
    }
}
