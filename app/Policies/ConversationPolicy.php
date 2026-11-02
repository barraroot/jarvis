<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use App\Policies\Concerns\AuthorizesOwner;

class ConversationPolicy
{
    use AuthorizesOwner;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Conversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Conversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    public function delete(User $user, Conversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    public function restore(User $user, Conversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    public function forceDelete(User $user, Conversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }
}
