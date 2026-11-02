<?php

namespace App\Policies;

use App\Models\Memory;
use App\Models\User;
use App\Policies\Concerns\AuthorizesOwner;

class MemoryPolicy
{
    use AuthorizesOwner;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Memory $memory): bool
    {
        return $this->owns($user, $memory);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Memory $memory): bool
    {
        return $this->owns($user, $memory);
    }

    /**
     * Confirm a hypothesis as a durable memory (US-6.2).
     */
    public function confirm(User $user, Memory $memory): bool
    {
        return $this->owns($user, $memory);
    }

    public function delete(User $user, Memory $memory): bool
    {
        return $this->owns($user, $memory);
    }
}
