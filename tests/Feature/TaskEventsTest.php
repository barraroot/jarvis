<?php

namespace Tests\Feature;

use App\Events\Task\TaskUpdated;
use App\Models\Agent;
use App\Models\AiModel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Visualization;
use App\Models\VoiceSession;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

/**
 * `task.updated` realtime event (US-10.2): every transition broadcasts the
 * task snapshot — status, progress and current step — on the owner's private
 * channel.
 */
class TaskEventsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->user = User::factory()->create();
    }

    public function test_task_updated_broadcasts_on_transitions(): void
    {
        Queue::fake();
        Event::fake([TaskUpdated::class]);

        $agent = Agent::factory()->create();
        $conversation = Conversation::factory()->for($this->user)->create();
        $message = Message::factory()->for($conversation)->for($this->user)->fromUser()
            ->create(['content' => 'Consolide o relatório do mês']);

        $task = app(TaskService::class)->createFromMessage($message, $agent, 'Relatório mensal');

        Event::assertDispatched(TaskUpdated::class, function (TaskUpdated $event) use ($task, $agent): bool {
            $this->assertSame('task.updated', $event->broadcastAs());
            $this->assertSame('private-user.'.$this->user->id, $event->broadcastOn()->name);

            $payload = $event->broadcastWith()['task'];

            return $payload['id'] === $task->id
                && $payload['status'] === TaskStatus::QUEUED
                && $payload['progress'] === 0
                && array_key_exists('current_step', $payload)
                && $payload['agent']['slug'] === $agent->slug;
        });

        app(TaskService::class)->markRunning($task->fresh());

        Event::assertDispatched(TaskUpdated::class, fn (TaskUpdated $event): bool => $event->broadcastWith()['task']['status'] === TaskStatus::RUNNING);

        app(TaskService::class)->markFailed($task->fresh(), 'boom');

        Event::assertDispatched(TaskUpdated::class, function (TaskUpdated $event): bool {
            $payload = $event->broadcastWith()['task'];

            return $payload['status'] === TaskStatus::FAILED && $payload['error'] === 'boom';
        });
    }

    public function test_task_updated_includes_current_step(): void
    {
        Event::fake([TaskUpdated::class]);

        $task = Task::factory()->running()->for($this->user)->create();

        $task->steps()->create([
            'task_status_id' => TaskStatus::idFor(TaskStatus::RUNNING),
            'position' => 1,
            'name' => 'Enviar lembretes',
            'started_at' => now(),
        ]);

        app(TaskService::class)->broadcastUpdated($task->fresh());

        Event::assertDispatched(TaskUpdated::class, function (TaskUpdated $event): bool {
            $step = $event->broadcastWith()['task']['current_step'];

            return $step['position'] === 1 && $step['name'] === 'Enviar lembretes' && $step['status'] === 'running';
        });
    }

    public function test_task_updated_includes_model_visualization_and_voice_summary(): void
    {
        Event::fake([TaskUpdated::class]);

        $model = AiModel::factory()->create(['name' => 'GPT Tarefas']);
        $task = Task::factory()->running()->for($this->user)->create([
            'ai_model_id' => $model->id,
            'voice_session_id' => VoiceSession::factory()->for($this->user),
        ]);
        $visualization = Visualization::factory()->for($this->user)->create(['task_id' => $task->id]);
        $task->update(['visualization_id' => $visualization->id]);

        app(TaskService::class)->broadcastUpdated($task->fresh());

        Event::assertDispatched(TaskUpdated::class, function (TaskUpdated $event) use ($visualization): bool {
            $payload = $event->broadcastWith()['task'];

            return $payload['model'] === ['name' => 'GPT Tarefas']
                && $payload['visualization_id'] === $visualization->id
                && array_key_exists('voice_summary', $payload)
                && $payload['voice_summary'] === null;
        });

        $task->fresh()->update([
            'task_status_id' => TaskStatus::idFor(TaskStatus::COMPLETED),
            'result' => ['type' => 'text', 'text' => 'Aula encontrada'],
        ]);

        app(TaskService::class)->broadcastUpdated($task->fresh());

        Event::assertDispatched(TaskUpdated::class, fn (TaskUpdated $event): bool => str_starts_with(
            (string) $event->broadcastWith()['task']['voice_summary'],
            '<tool_result name="task" trusted="false">Aula encontrada',
        ));
    }
}
