<?php

namespace App\Policies;

use App\Models\Notification;
use App\Models\User;
use App\Policies\Concerns\AuthorizesOwner;

class NotificationPolicy
{
    use AuthorizesOwner;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Notification $notification): bool
    {
        return $this->owns($user, $notification);
    }

    public function update(User $user, Notification $notification): bool
    {
        return $this->owns($user, $notification);
    }

    public function markRead(User $user, Notification $notification): bool
    {
        return $this->owns($user, $notification);
    }

    public function delete(User $user, Notification $notification): bool
    {
        return $this->owns($user, $notification);
    }
}
