<?php

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Policies\NotificationPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The notification centre (US-10.4): the operator lists notifications
 * (`?unread=1` for the unread slice), marks one read or all of them at once.
 * Owner gated by {@see NotificationPolicy}.
 */
class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Notification::class);

        $notifications = Notification::query()
            ->with('type')
            ->where('user_id', $request->user()->getKey())
            ->when($request->query('unread') === '1', fn ($query) => $query->whereNull('read_at'))
            ->latest('id')
            ->paginate(20);

        return NotificationResource::collection($notifications);
    }

    public function read(Request $request, Notification $notification): JsonResponse
    {
        Gate::authorize('markRead', $notification);

        $notification->markRead();

        return response()->json([
            'data' => NotificationResource::make($notification->fresh('type'))->resolve(),
        ]);
    }

    public function readAll(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Notification::class);

        Notification::query()
            ->where('user_id', $request->user()->getKey())
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => ['status' => 'ok']]);
    }
}
