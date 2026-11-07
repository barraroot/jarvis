<?php

namespace App\Http\Resources;

use App\Models\WhatsappConsent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * CT-01 response: the accepted version, scopes and whether the consent is
 * valid for the current text (RF-09, RF-12).
 *
 * @mixin WhatsappConsent
 */
class WhatsappConsentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'version' => $this->version,
            'scopes' => array_values($this->scopes ?? []),
            'consented_at' => $this->consented_at?->toIso8601String(),
            'valid' => $this->isValid(),
        ];
    }
}
