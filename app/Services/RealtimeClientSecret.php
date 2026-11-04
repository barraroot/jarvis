<?php

namespace App\Services;

use App\Models\AiModel;
use Carbon\CarbonImmutable;

/**
 * Ephemeral Realtime credential issued for the SPA (RF-09, RNF-02). Carries
 * only the short-lived secret, never the provider key.
 */
final readonly class RealtimeClientSecret
{
    public function __construct(
        public string $value,
        public CarbonImmutable $expiresAt,
        public AiModel $model,
        public string $voice,
    ) {}
}
