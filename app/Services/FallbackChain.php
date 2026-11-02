<?php

namespace App\Services;

use App\Models\AiModel;
use App\Models\AiModelCapability;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the ordered list of text models to try for a request (US-3.4).
 *
 * The chain is the primary model followed by every active text model with a
 * `fallback_order`, ordered by that column. Inactive models, models of
 * inactive providers and embedding-only models never take part, and the
 * primary model is not repeated when it also has a fallback order.
 */
class FallbackChain
{
    /**
     * @return list<AiModel>
     */
    public function for(AiModel $primary): array
    {
        $chain = $this->isUsable($primary) ? [$primary] : [];

        $fallbacks = AiModel::query()
            ->with('provider')
            ->active()
            ->text()
            ->whereHas('provider', fn (Builder $query) => $query->active())
            ->whereNotNull('fallback_order')
            ->orderBy('fallback_order')
            ->get();

        foreach ($fallbacks as $fallback) {
            if ($fallback->is($primary)) {
                continue;
            }

            $chain[] = $fallback;
        }

        return $chain;
    }

    private function isUsable(AiModel $model): bool
    {
        return $model->is_active
            && $model->provider !== null
            && $model->provider->is_active
            && $model->hasCapability(AiModelCapability::TEXT);
    }
}
