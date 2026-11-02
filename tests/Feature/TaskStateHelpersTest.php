<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskLog;
use App\Models\TaskStatus;
use App\Models\TaskStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class TaskStateHelpersTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool, 3: bool}>
     */
    public static function statusMatrix(): array
    {
        // slug => [cancellable, retryable, terminal]
        return [
            'pending' => [TaskStatus::PENDING, false, false, false],
            'queued' => [TaskStatus::QUEUED, true, false, false],
            'running' => [TaskStatus::RUNNING, true, false, false],
            'waiting_approval' => [TaskStatus::WAITING_APPROVAL, true, false, false],
            'completed' => [TaskStatus::COMPLETED, false, false, true],
            'failed' => [TaskStatus::FAILED, false, true, true],
            'cancelled' => [TaskStatus::CANCELLED, false, false, true],
        ];
    }

    public function test_can_be_cancelled_only_in_queued_running_or_waiting_approval(): void
    {
        foreach (self::statusMatrix() as [$slug, $cancellable]) {
            $task = Task::factory()->status($slug)->create();

            $this->assertSame($cancellable, $task->canBeCancelled(), "canBeCancelled() for [{$slug}]");
        }
    }

    public function test_can_be_retried_only_when_failed(): void
    {
        foreach (self::statusMatrix() as [$slug, , $retryable, $terminal]) {
            $task = Task::factory()->status($slug)->create();

            $this->assertSame($retryable, $task->canBeRetried(), "canBeRetried() for [{$slug}]");
            $this->assertSame($terminal, $task->isTerminal(), "isTerminal() for [{$slug}]");
        }
    }

    public function test_factory_states_cover_every_task_status(): void
    {
        $tasks = [
            Task::factory()->pending()->create(),
            Task::factory()->queued()->create(),
            Task::factory()->running()->create(),
            Task::factory()->waitingApproval()->create(),
            Task::factory()->completed()->create(),
            Task::factory()->failed()->create(),
            Task::factory()->cancelled()->create(),
        ];

        $slugs = array_map(fn (Task $task): string => $task->statusSlug(), $tasks);

        $this->assertEqualsCanonicalizing(TaskStatus::query()->pluck('slug')->all(), $slugs);
        $this->assertSame(100, $tasks[4]->progress);
        $this->assertSame('text', $tasks[4]->resultType->slug);
    }

    public function test_retry_chain_steps_order_and_logs(): void
    {
        $failed = Task::factory()->failed()->create();
        $retry = Task::factory()->retryOf($failed)->queued()->create();

        $second = TaskStep::factory()->for($failed)->create(['position' => 2, 'name' => 'second']);
        $first = TaskStep::factory()->for($failed)->create(['position' => 1, 'name' => 'first']);
        $log = TaskLog::factory()->for($failed)->for($first, 'step')->error()->create();

        $this->assertTrue($retry->retryOf->is($failed));
        $this->assertTrue($failed->retries->contains($retry));
        $this->assertSame([$first->id, $second->id], $failed->steps->modelKeys());
        $this->assertSame('error', $log->level->slug);
        $this->assertTrue($first->logs->contains($log));
        $this->assertNull(TaskLog::UPDATED_AT);
    }
}
