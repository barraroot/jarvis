<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WhatsappSession;
use App\Policies\Concerns\AuthorizesOwner;

class WhatsappSessionPolicy
{
    use AuthorizesOwner;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WhatsappSession $whatsappSession): bool
    {
        return $this->owns($user, $whatsappSession);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, WhatsappSession $whatsappSession): bool
    {
        return $this->owns($user, $whatsappSession);
    }

    public function delete(User $user, WhatsappSession $whatsappSession): bool
    {
        return $this->owns($user, $whatsappSession);
    }
}
