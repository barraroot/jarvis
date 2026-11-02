<?php

namespace Tests\Feature;

use App\Events\Task\TaskUpdated;
use App\Jobs\RunTask;
use App\Models\Agent;
use App\Models\LogLevel;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

/**
 * Task API (US-10.2, US-10.3): owner-scoped listing, detail with steps/logs
 * and result, cancel/retry with 409 on state violations and 403 for others.
 */
class TaskApiTest extends TestCase
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

        $this->actingAs($this->user);
    }

    public function test_lists_only_own_tasks(): void
    {
        $running = Task::factory()->running()->for($this->user)->for($this->agent)->create(['progress' => 40]);
        $waiting = Task::factory()->waitingApproval()->for($this->user)->for($this->agent)->create();
        Task::factory()->completed()->for($this->user)->for($this->agent)->create();
        Task::factory()->running()->for(User::factory())->create();

        $this->getJson('/api/v1/tasks?active=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $waiting->id)
            ->assertJsonPath('data.0.status', 'waiting_approval')
            ->assertJsonPath('data.0.agent.slug', $this->agent->slug)
            ->assertJsonPath('data.1.id', $running->id)
            ->assertJsonPath('data.1.progress', 40);

        $this->getJson('/api/v1/tasks')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_show_includes_steps_logs_and_result(): void
    {
        $task = Task::factory()->completed()->for($this->user)->for($this->agent)->create();

        $task->steps()->createMany([
            [
                'task_status_id' => TaskStatus::idFor(TaskStatus::COMPLETED),
                'position' => 1,
                'name' => 'Buscar clientes',
                'output' => ['rows' => 12],
                'started_at' => now()->subMinutes(2),
                'finished_at' => now()->subMinute(),
            ],
            [
                'task_status_id' => TaskStatus::idFor(TaskStatus::COMPLETED),
                'position' => 2,
                'name' => 'Gerar relatório',
                'output' => ['pages' => 1],
                'started_at' => now()->subMinute(),
                'finished_at' => now()->subSeconds(30),
            ],
        ]);

        $task->logs()->create([
            'task_step_id' => $task->steps()->first()->id,
            'log_level_id' => LogLevel::idFor(LogLevel::INFO),
            'message' => 'Consulta iniciada',
            'created_at' => now()->subMinutes(2),
        ]);
        $task->logs()->create([
            'log_level_id' => LogLevel::idFor(LogLevel::ERROR),
            'message' => 'Falha parcial na exportação',
            'created_at' => now()->subMinute(),
        ]);

        $this->getJson("/api/v1/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.progress', 100)
            ->assertJsonPath('data.result_type', 'text')
            ->assertJsonPath('data.result.text', $task->result['text'])
            ->assertJsonPath('data.current_step.position', 2)
            ->assertJsonPath('data.current_step.name', 'Gerar relatório')
            ->assertJsonCount(2, 'data.steps')
            ->assertJsonPath('data.steps.0.position', 1)
            ->assertJsonPath('data.steps.0.status', 'completed')
            ->assertJsonPath('data.steps.0.output.rows', 12)
            ->assertJsonCount(2, 'data.logs.data')
            ->assertJsonPath('data.logs.data.0.level', 'error')
            ->assertJsonPath('data.logs.data.1.message', 'Consulta iniciada');
    }

    public function test_cancel_and_retry_respect_state_rules_with_409(): void
    {
        Queue::fake([RunTask::class]);
        Event::fake([TaskUpdated::class]);

        $running = Task::factory()->running()->for($this->user)->for($this->agent)->create();
        $failed = Task::factory()->failed()->for($this->user)->for($this->agent)->create();
        $completed = Task::factory()->completed()->for($this->user)->for($this->agent)->create();

        $this->postJson("/api/v1/tasks/{$completed->id}/cancel")
            ->assertStatus(409);

        $this->postJson("/api/v1/tasks/{$running->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->postJson("/api/v1/tasks/{$completed->id}/retry")
            ->assertStatus(409);

        $this->postJson("/api/v1/tasks/{$failed->id}/retry")
            ->assertCreated()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.retry_of_task_id', $failed->id);

        $this->assertDatabaseHas('tasks', ['id' => $failed->id]);
        $this->assertSame(1, Task::query()->where('retry_of_task_id', $failed->id)->count());

        Queue::assertPushedOn('tasks', RunTask::class, fn (RunTask $job): bool => $job->task->retry_of_task_id === $failed->id);
    }

    public function test_other_users_tasks_are_forbidden(): void
    {
        $other = User::factory()->create();
        $task = Task::factory()->running()->for($other)->create();

        $this->getJson("/api/v1/tasks/{$task->id}")->assertStatus(403);
        $this->postJson("/api/v1/tasks/{$task->id}/cancel")->assertStatus(403);
        $this->postJson("/api/v1/tasks/{$task->id}/retry")->assertStatus(403);
    }
}
