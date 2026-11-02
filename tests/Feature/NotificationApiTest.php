<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\NotificationType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

/**
 * Notification API (US-10.4, US-1.3): unread listing, mark-one-read and
 * mark-all-read, strictly scoped to the owner's notifications.
 */
class NotificationApiTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->user = User::factory()->create();

        $this->actingAs($this->user);
    }

    public function test_mark_read_and_read_all_only_affect_own_notifications(): void
    {
        $first = $this->notification(['approval_id' => 7]);
        $second = $this->notification(['task_id' => 3]);
        $this->notification([], read: true);

        $other = User::factory()->create();
        $theirs = Notification::factory()->for($other)->create();

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.notifications_unread', 2);

        $this->getJson('/api/v1/notifications?unread=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.0.type', 'task_failed')
            ->assertJsonPath('data.0.data.task_id', 3)
            ->assertJsonPath('data.1.id', $first->id)
            ->assertJsonPath('data.1.type', 'approval_pending');

        $this->postJson("/api/v1/notifications/{$first->id}/read")
            ->assertOk()
            ->assertJsonPath('data.read_at', fn ($readAt): bool => $readAt !== null);

        $this->assertNotNull($first->fresh()->read_at);
        $this->assertNull($second->fresh()->read_at);

        // Idempotent: marking an already-read notification again changes nothing.
        $this->postJson("/api/v1/notifications/{$first->id}/read")->assertOk();

        $this->postJson("/api/v1/notifications/{$theirs->id}/read")->assertStatus(403);

        $this->postJson('/api/v1/notifications/read-all')->assertOk();

        $this->assertNotNull($second->fresh()->read_at);
        $this->assertNull($theirs->fresh()->read_at);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.notifications_unread', 0);

        $this->getJson('/api/v1/notifications?unread=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_lists_only_own_notifications(): void
    {
        Notification::factory()->for($this->user)->create();
        Notification::factory()->for(User::factory())->create();
        Notification::factory()->for(User::factory())->create();

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function notification(array $data, bool $read = false): Notification
    {
        return Notification::factory()->for($this->user)->create([
            'notification_type_id' => NotificationType::idFor(
                array_key_exists('task_id', $data) ? NotificationType::TASK_FAILED : NotificationType::APPROVAL_PENDING,
            ),
            'data' => $data,
            'read_at' => $read ? now() : null,
        ]);
    }
}
