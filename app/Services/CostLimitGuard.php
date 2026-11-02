<?php

namespace App\Services;

use App\Exceptions\UsageLimitExceededException;
use App\Models\Notification;
use App\Models\NotificationType;
use App\Models\UsageRecord;
use App\Models\User;

/**
 * Blocks AI calls when a user's monthly cost limit is exceeded (US-5.3).
 *
 * Users without a `monthly_cost_limit` are never blocked. The first breach of
 * each month creates a `usage_limit_reached` notification; repeated breaches
 * in the same month do not spam.
 */
class CostLimitGuard
{
    public function assertWithinLimit(User $user): void
    {
        if ($user->monthly_cost_limit === null) {
            return;
        }

        $spent = $this->monthlySpent($user);

        if ($spent <= (float) $user->monthly_cost_limit) {
            return;
        }

        $this->notifyOncePerMonth($user, $spent);

        throw new UsageLimitExceededException(sprintf(
            'Monthly usage limit of %.2f exceeded (spent %.2f this month).',
            (float) $user->monthly_cost_limit,
            $spent,
        ));
    }

    private function monthlySpent(User $user): float
    {
        return (float) UsageRecord::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('estimated_cost');
    }

    private function notifyOncePerMonth(User $user, float $spent): void
    {
        $alreadyNotified = Notification::query()
            ->where('user_id', $user->id)
            ->where('notification_type_id', NotificationType::idFor(NotificationType::USAGE_LIMIT_REACHED))
            ->where('created_at', '>=', now()->startOfMonth())
            ->exists();

        if ($alreadyNotified) {
            return;
        }

        Notification::query()->create([
            'user_id' => $user->id,
            'notification_type_id' => NotificationType::idFor(NotificationType::USAGE_LIMIT_REACHED),
            'title' => 'Limite mensal de uso de IA atingido',
            'body' => sprintf(
                'O consumo estimado deste mês (%.2f) excedeu o limite configurado (%.2f). Novas chamadas de IA estão bloqueadas.',
                $spent,
                (float) $user->monthly_cost_limit,
            ),
            'data' => [
                'limit' => (float) $user->monthly_cost_limit,
                'spent' => $spent,
            ],
        ]);
    }
}
