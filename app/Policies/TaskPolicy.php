<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Policies\Concerns\AuthorizesOwner;

class TaskPolicy
{
    use AuthorizesOwner;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Task $task): bool
    {
        return $this->owns($user, $task);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Task $task): bool
    {
        return $this->owns($user, $task);
    }

    public function cancel(User $user, Task $task): bool
    {
        return $this->owns($user, $task) && $task->canBeCancelled();
    }

    public function retry(User $user, Task $task): bool
    {
        return $this->owns($user, $task) && $task->canBeRetried();
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->owns($user, $task);
    }
}
