<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TaskSchemaTest extends TestCase
{
    use RefreshDatabase;

    private int $taskId;

    private int $statusId;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();

        $this->statusId = DB::table('task_statuses')->insertGetId([
            'name' => 'Pending',
            'slug' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $agentId = DB::table('agents')->insertGetId([
            'name' => 'Research',
            'slug' => 'research',
            'description' => 'Research agent',
            'objective' => 'Research',
            'instructions' => 'Research things',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->taskId = DB::table('tasks')->insertGetId([
            'user_id' => $user->id,
            'agent_id' => $agentId,
            'task_status_id' => $this->statusId,
            'title' => 'Summarize report',
            'request' => 'Summarize the quarterly report',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_task_step_position_is_unique_per_task(): void
    {
        $this->insertStep(1, 'Collect data');

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(function (): void {
            $this->insertStep(1, 'Collect data again');
        });
    }

    public function test_task_defaults_progress_and_timeout(): void
    {
        $task = DB::table('tasks')->find($this->taskId);

        $this->assertSame(0, (int) $task->progress);
        $this->assertSame(900, (int) $task->timeout_seconds);
        $this->assertNull($task->retry_of_task_id);
    }

    public function test_task_can_reference_the_task_it_retries(): void
    {
        $retryId = DB::table('tasks')->insertGetId([
            'user_id' => DB::table('tasks')->where('id', $this->taskId)->value('user_id'),
            'agent_id' => DB::table('tasks')->where('id', $this->taskId)->value('agent_id'),
            'task_status_id' => $this->statusId,
            'retry_of_task_id' => $this->taskId,
            'title' => 'Summarize report (retry)',
            'request' => 'Summarize the quarterly report',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame($this->taskId, (int) DB::table('tasks')->where('id', $retryId)->value('retry_of_task_id'));
    }

    public function test_task_logs_have_only_created_at_and_cascade_with_task(): void
    {
        $this->assertFalse(Schema::hasColumn('task_logs', 'updated_at'));

        $levelId = DB::table('log_levels')->insertGetId([
            'name' => 'Info',
            'slug' => 'info',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('task_logs')->insert([
            'task_id' => $this->taskId,
            'log_level_id' => $levelId,
            'message' => 'Started',
            'created_at' => now(),
        ]);

        DB::table('tasks')->where('id', $this->taskId)->delete();

        $this->assertDatabaseMissing('task_logs', ['task_id' => $this->taskId]);
    }

    private function insertStep(int $position, string $name): void
    {
        DB::table('task_steps')->insert([
            'task_id' => $this->taskId,
            'task_status_id' => $this->statusId,
            'position' => $position,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
