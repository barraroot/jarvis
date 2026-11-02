<?php

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared ownership check for user-scoped records (US-1.3): only the owner may act.
 */
trait AuthorizesOwner
{
    protected function owns(User $user, Model $record, string $foreignKey = 'user_id'): bool
    {
        return (int) $record->getAttribute($foreignKey) === (int) $user->getKey();
    }
}
