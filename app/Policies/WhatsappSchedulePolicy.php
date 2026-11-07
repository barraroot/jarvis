<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WhatsappSchedule;
use App\Policies\Concerns\AuthorizesOwner;

class WhatsappSchedulePolicy
{
    use AuthorizesOwner;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WhatsappSchedule $whatsappSchedule): bool
    {
        return $this->owns($user, $whatsappSchedule);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, WhatsappSchedule $whatsappSchedule): bool
    {
        return $this->owns($user, $whatsappSchedule);
    }

    public function delete(User $user, WhatsappSchedule $whatsappSchedule): bool
    {
        return $this->owns($user, $whatsappSchedule);
    }
}
