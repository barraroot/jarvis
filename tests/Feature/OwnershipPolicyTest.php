<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Conversation;
use App\Models\McpServer;
use App\Models\Memory;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Task;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class OwnershipPolicyTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    public function test_user_cannot_view_update_or_delete_other_users_records(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $records = [
            'conversation' => Conversation::factory()->for($owner)->create(),
            'memory' => Memory::factory()->for($owner)->create(),
            'task' => Task::factory()->for($owner)->running()->create(),
            'approval' => Approval::factory()->for($owner)->pending()->create(),
            'notification' => Notification::factory()->for($owner)->create(),
            'personal connector' => McpServer::factory()->for($owner)->create(),
        ];

        foreach ($records as $label => $record) {
            foreach (['view', 'update', 'delete'] as $ability) {
                $this->assertTrue($owner->can($ability, $record), "Owner should be able to {$ability} {$label}.");
                $this->assertFalse($intruder->can($ability, $record), "Intruder must not {$ability} {$label}.");
            }
        }

        $this->assertTrue($owner->can('decide', $records['approval']));
        $this->assertFalse($intruder->can('decide', $records['approval']));

        $this->assertTrue($owner->can('cancel', $records['task']));
        $this->assertFalse($intruder->can('cancel', $records['task']));
        $this->assertFalse($owner->can('retry', $records['task']), 'A running task cannot be retried.');

        $this->assertTrue($owner->can('markRead', $records['notification']));
        $this->assertFalse($intruder->can('markRead', $records['notification']));

        $this->assertTrue($owner->can('resync', $records['personal connector']));
        $this->assertFalse($intruder->can('resync', $records['personal connector']));
    }

    public function test_messages_and_preferences_are_authorized_through_their_owner(): void
    {
        $owner = User::factory()->withPreference()->create();
        $intruder = User::factory()->create();

        $message = Message::factory()->for(Conversation::factory()->for($owner))->create();
        $preference = UserPreference::query()->where('user_id', $owner->id)->firstOrFail();

        $this->assertTrue($owner->can('view', $message));
        $this->assertFalse($intruder->can('view', $message));
        $this->assertFalse($intruder->can('delete', $message));

        $this->assertTrue($owner->can('update', $preference));
        $this->assertFalse($intruder->can('view', $preference));
        $this->assertFalse($intruder->can('update', $preference));
    }

    public function test_admin_has_no_implicit_access_to_other_users_personal_records(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $conversation = Conversation::factory()->for($owner)->create();
        $memory = Memory::factory()->for($owner)->create();

        $this->assertFalse($admin->can('view', $conversation));
        $this->assertFalse($admin->can('delete', $memory));
    }
}
