<?php

namespace App\Policies;

use App\Models\Approval;
use App\Models\User;
use App\Policies\Concerns\AuthorizesOwner;

class ApprovalPolicy
{
    use AuthorizesOwner;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Approval $approval): bool
    {
        return $this->owns($user, $approval);
    }

    /**
     * Only the owner may approve or deny (US-8.3).
     */
    public function decide(User $user, Approval $approval): bool
    {
        return $this->owns($user, $approval);
    }

    public function update(User $user, Approval $approval): bool
    {
        return $this->decide($user, $approval);
    }

    public function delete(User $user, Approval $approval): bool
    {
        return $this->owns($user, $approval);
    }
}
