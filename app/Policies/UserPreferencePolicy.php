<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserPreference;
use App\Policies\Concerns\AuthorizesOwner;

class UserPreferencePolicy
{
    use AuthorizesOwner;

    public function view(User $user, UserPreference $preference): bool
    {
        return $this->owns($user, $preference);
    }

    public function update(User $user, UserPreference $preference): bool
    {
        return $this->owns($user, $preference);
    }
}
