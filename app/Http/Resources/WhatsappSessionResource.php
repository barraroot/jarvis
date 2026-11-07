<?php

namespace App\Http\Resources;

use App\Models\WhatsappConsent;
use App\Models\WhatsappSession;
use App\Services\WhatsappSendGuard;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * CT-03/CT-04 session: status, suspension, the owner's consent against the
 * current text and today's send usage (RF-12, RF-22). Expects `status` and
 * `user.whatsappConsent` eager loaded. Never carries the token, HMAC key,
 * webhook key, QR or pairing code (RNF-01, RNF-10).
 *
 * @mixin WhatsappSession
 */
class WhatsappSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WhatsappConsent|null $consent */
        $consent = $this->user->whatsappConsent;

        return [
            'id' => $this->id,
            'status' => $this->statusSlug(),
            'suspended' => $this->isSuspended(),
            'phone_masked' => $this->phone_masked,
            'last_error' => $this->last_error,
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'connected_at' => $this->connected_at?->toIso8601String(),
            'consent' => [
                'version' => $consent?->version,
                'scopes' => array_values($consent->scopes ?? []),
                'valid' => $consent?->isValid() ?? false,
            ],
            'current_consent_version' => WhatsappConsent::CURRENT_VERSION,
            'usage_today' => [
                'sends' => app(WhatsappSendGuard::class)->unitsToday($this->resource),
                'cap' => app(Settings::class)->whatsappDailySendCap(),
            ],
        ];
    }
}
