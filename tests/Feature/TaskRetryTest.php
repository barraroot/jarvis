<?php

namespace Tests\Feature;

use App\Events\Task\TaskUpdated;
use App\Exceptions\TaskNotRetryableException;
use App\Jobs\RunTask;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

/**
 * Retry semantics (US-10.3): only failed tasks create a linked retry with the
 * same request and agent; every other state is rejected.
 */
class TaskRetryTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->user = User::factory()->create();
        $this->agent = Agent::factory()->create();
    }

    public function test_retry_creates_linked_task_only_when_failed(): void
    {
        Queue::fake();
        Event::fake([TaskUpdated::class]);

        $conversation = Conversation::factory()->for($this->user)->create();
        $message = Message::factory()->for($conversation)->for($this->user)->fromUser()
            ->create(['content' => 'Gere o relatório mensal']);

        $failed = Task::factory()->failed()->for($this->user)->for($this->agent)->create([
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'request' => 'Gere o relatório mensal',
        ]);

        $retry = app(TaskService::class)->retry($failed, $this->user);

        $this->assertSame($failed->id, $retry->retry_of_task_id);
        $this->assertSame($failed->agent_id, $retry->agent_id);
        $this->assertSame($failed->request, $retry->request);
        $this->assertSame($failed->title, $retry->title);
        $this->assertSame($failed->conversation_id, $retry->conversation_id);
        $this->assertSame(TaskStatus::QUEUED, $retry->status->slug);
        $this->assertNotNull($retry->queued_at);
        $this->assertSame($this->user->id, $retry->user_id);

        Queue::assertPushedOn('tasks', RunTask::class, fn (RunTask $job): bool => $job->task->is($retry));

        foreach ([TaskStatus::COMPLETED, TaskStatus::CANCELLED, TaskStatus::RUNNING, TaskStatus::QUEUED, TaskStatus::WAITING_APPROVAL] as $slug) {
            $task = Task::factory()->status($slug)->for($this->user)->for($this->agent)->create();

            $this->assertThrows(
                fn (): mixed => app(TaskService::class)->retry($task, $this->user),
                TaskNotRetryableException::class,
            );
        }

        $this->assertSame(1, Task::query()->whereNotNull('retry_of_task_id')->count());
    }
}
