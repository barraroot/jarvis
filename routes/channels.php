<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels
|--------------------------------------------------------------------------
|
| `private-user.{id}` is the per-operator realtime channel (assistant state,
| response chunks, agents, tools, notifications). Only its owner may join.
|
*/

Broadcast::channel('user.{id}', function (User $user, int|string $id): bool {
    return $user->id === (int) $id;
});
