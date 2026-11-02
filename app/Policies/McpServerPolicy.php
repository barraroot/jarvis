<?php

namespace App\Policies;

use App\Models\McpServer;
use App\Models\User;
use App\Policies\Concerns\AuthorizesOwner;

/**
 * Global connectors are visible to everyone but managed only by admins;
 * personal connectors are managed only by their owner (US-9.1, US-9.2).
 */
class McpServerPolicy
{
    use AuthorizesOwner;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, McpServer $server): bool
    {
        return $server->isGlobal() || $this->owns($user, $server);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, McpServer $server): bool
    {
        return $this->manages($user, $server);
    }

    public function delete(User $user, McpServer $server): bool
    {
        return $this->manages($user, $server);
    }

    public function resync(User $user, McpServer $server): bool
    {
        return $this->manages($user, $server);
    }

    private function manages(User $user, McpServer $server): bool
    {
        if ($server->isGlobal()) {
            return $user->isAdmin();
        }

        return $this->owns($user, $server);
    }
}
