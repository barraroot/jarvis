<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;

/**
 * Messages are authorized through their conversation's owner.
 */
class MessagePolicy
{
    public function view(User $user, Message $message): bool
    {
        return $this->ownsConversation($user, $message);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Message $message): bool
    {
        return $this->ownsConversation($user, $message);
    }

    public function delete(User $user, Message $message): bool
    {
        return $this->ownsConversation($user, $message);
    }

    private function ownsConversation(User $user, Message $message): bool
    {
        $conversation = $message->conversation()->withTrashed()->first();

        return $conversation !== null && (int) $conversation->user_id === (int) $user->getKey();
    }
}
