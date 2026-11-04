<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Visualization;
use App\Policies\Concerns\AuthorizesOwner;

class VisualizationPolicy
{
    use AuthorizesOwner;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Visualization $visualization): bool
    {
        return $this->owns($user, $visualization);
    }

    public function update(User $user, Visualization $visualization): bool
    {
        return $this->owns($user, $visualization);
    }

    /**
     * Close the panel without touching the linked task (RF-19a).
     */
    public function close(User $user, Visualization $visualization): bool
    {
        return $this->owns($user, $visualization);
    }

    public function delete(User $user, Visualization $visualization): bool
    {
        return $this->owns($user, $visualization);
    }
}
