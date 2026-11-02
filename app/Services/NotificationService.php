<?php

namespace App\Services;

use App\Events\NotificationCreated;
use App\Models\Notification;
use App\Models\NotificationType;
use App\Models\User;

/**
 * Central notification creation (US-10.4): every trigger — task completion or
 * failure, pending approvals, connector errors, usage limits — goes through
 * here, so `notification.created` always fires with the same shape.
 */
class NotificationService
{
    /**
     * Create a notification for the operator and broadcast `notification.created`.
     *
     * @param  array<string, mixed>  $data  Deep-link payload (task_id, approval_id, ...).
     */
    public function notify(User $user, string $typeSlug, string $title, ?string $body = null, array $data = []): Notification
    {
        $notification = Notification::query()->create([
            'user_id' => $user->id,
            'notification_type_id' => NotificationType::idFor($typeSlug),
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);

        NotificationCreated::dispatch($notification);

        return $notification;
    }
}
