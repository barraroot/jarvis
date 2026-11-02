<?php

namespace Tests\Feature;

use App\Events\Conversation\ConversationMessageCreated;
use App\Models\Approval;
use App\Models\ApprovalStatus;
use App\Models\AuditAction;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class ExpireApprovalsCommandTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();
    }

    public function test_marks_expired_and_audits(): void
    {
        $user = User::factory()->create();

        $expiredOne = Approval::factory()->for($user)->create([
            'requested_at' => now()->subDay(),
            'expires_at' => now()->subHour(),
        ]);
        $expiredTwo = Approval::factory()->for($user)->create([
            'requested_at' => now()->subDay(),
            'expires_at' => now()->subMinute(),
        ]);
        $stillValid = Approval::factory()->for($user)->create([
            'requested_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        $alreadyDecided = Approval::factory()->for($user)->denied()->create();

        $this->artisan('jarvis:approvals:expire')->assertSuccessful();

        foreach ([$expiredOne, $expiredTwo] as $approval) {
            $approval->refresh();

            $this->assertSame(ApprovalStatus::EXPIRED, $approval->status->slug);
            $this->assertNotNull($approval->decided_at);

            $this->assertDatabaseHas('audit_logs', [
                'audit_action_id' => AuditAction::idFor(AuditAction::APPROVAL_EXPIRED),
                'user_id' => null,
                'subject_type' => 'App\\Models\\Approval',
                'subject_id' => $approval->id,
            ]);
        }

        $this->assertTrue($stillValid->fresh()->isPending());
        $this->assertSame(ApprovalStatus::DENIED, $alreadyDecided->fresh()->status->slug);
        $this->assertSame(2, AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::APPROVAL_EXPIRED))->count());
    }

    public function test_expire_publishes_denial_message_in_conversation(): void
    {
        Event::fake([ConversationMessageCreated::class]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        $task = Task::factory()->waitingApproval()->for($user)->create(['conversation_id' => $conversation->id]);

        Approval::factory()->for($user)->create([
            'task_id' => $task->id,
            'requested_at' => now()->subDay(),
            'expires_at' => now()->subHour(),
        ]);

        $this->artisan('jarvis:approvals:expire')->assertSuccessful();

        $message = Message::query()->where('conversation_id', $conversation->id)->firstOrFail();

        $this->assertSame('approval_denied', $message->payload['type']);
        $this->assertSame('expired', $message->payload['decision']);
        $this->assertStringContainsString('expirou sem decisão', $message->content);
    }
}
