<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VoiceSession;
use App\Policies\Concerns\AuthorizesOwner;

class VoiceSessionPolicy
{
    use AuthorizesOwner;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, VoiceSession $voiceSession): bool
    {
        return $this->owns($user, $voiceSession);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, VoiceSession $voiceSession): bool
    {
        return $this->owns($user, $voiceSession);
    }

    public function delete(User $user, VoiceSession $voiceSession): bool
    {
        return $this->owns($user, $voiceSession);
    }
}
