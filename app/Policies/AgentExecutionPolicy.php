<?php

namespace App\Policies;

use App\Models\AgentExecution;
use App\Models\User;
use App\Policies\Concerns\AuthorizesOwner;

/**
 * Agent executions are operator-private (US-7.4, US-12.4): listing returns
 * only the owner's runs; viewing requires ownership (US-1.3).
 */
class AgentExecutionPolicy
{
    use AuthorizesOwner;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AgentExecution $execution): bool
    {
        return $this->owns($user, $execution);
    }
}
