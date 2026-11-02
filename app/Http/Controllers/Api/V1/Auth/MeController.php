<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Returns the authenticated operator with their preferences (US-4.5), creating the default preference row on demand.
 */
class MeController extends Controller
{
    public function __invoke(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        $preference = $user->preference()->firstOrCreate();

        if ($preference->wasRecentlyCreated) {
            // Reload so the column defaults (locale, speech rate, ...) are present in the response.
            $preference->refresh();
        }

        $user->setRelation('preference', $preference);

        return UserResource::make($user);
    }
}
