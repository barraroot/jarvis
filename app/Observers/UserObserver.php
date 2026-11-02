<?php

namespace App\Observers;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deactivating an operator ends every session they hold (US-1.4).
 */
class UserObserver
{
    public function updated(User $user): void
    {
        if ($user->wasChanged('is_active') && ! $user->is_active) {
            $this->terminateSessions($user);
        }
    }

    /**
     * Remove persisted sessions from the `sessions` table. Drivers without a user index
     * (redis, file) are covered by the `active` middleware, which rejects the next request.
     */
    private function terminateSessions(User $user): void
    {
        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->delete();
    }
}
