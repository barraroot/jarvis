<?php

namespace Tests\Feature;

use App\Events\NotificationCreated;
use App\Events\Task\TaskUpdated;
use App\Jobs\RunTask;
use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\ExecutionStatus;
use App\Models\Notification;
use App\Models\NotificationType;
use App\Models\Task;
use App\Models\User;
use App\Services\AgentRunner;
use App\Services\AgentRuntimeFactory;
use App\Services\ProviderRegistry;
use App\Services\RunOptions;
use App\Services\TaskService;
use App\Services\UsageRecorder;
use App\Support\Settings;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

/**
 * Task outcome notifications (US-10.4): `RunTask` notifies the owner on
 * completion and failure, and every notification broadcasts
 * `notification.created`.
 */
class NotificationServiceTest extends TestCase
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

    public function test_task_completion_and_failure_create_notifications_and_broadcast(): void
    {
        Queue::fake();
        Event::fake([NotificationCreated::class, TaskUpdated::class]);

        $completed = Task::factory()->queued()->for($this->user)->for($this->agent)->create(['title' => 'Relatório mensal']);

        $this->stubRunner(fn (): AgentExecution => $this->completedExecution());

        (new RunTask($completed))->handle(app(TaskService::class));

        $notification = Notification::query()->where('user_id', $this->user->id)->firstOrFail();

        $this->assertSame(NotificationType::TASK_COMPLETED, $notification->type->slug);
        $this->assertSame('Tarefa concluída', $notification->title);
        $this->assertStringContainsString('Relatório mensal', $notification->body);
        $this->assertSame($completed->id, $notification->data['task_id']);
        $this->assertNull($notification->read_at);

        Event::assertDispatched(NotificationCreated::class, fn (NotificationCreated $event): bool => $event->notification->is($notification));

        $failed = Task::factory()->queued()->for($this->user)->for($this->agent)->create(['title' => 'Importação do ERP']);

        $this->stubRunner(fn (): never => throw new RuntimeException('A conexão caiu.'));

        (new RunTask($failed))->handle(app(TaskService::class));

        $failure = Notification::query()
            ->where('user_id', $this->user->id)
            ->where('notification_type_id', NotificationType::idFor(NotificationType::TASK_FAILED))
            ->firstOrFail();

        $this->assertSame('Tarefa falhou', $failure->title);
        $this->assertStringContainsString('Importação do ERP', $failure->body);
        $this->assertSame($failed->id, $failure->data['task_id']);

        $this->assertSame(2, Notification::query()->count());
        Event::assertDispatched(NotificationCreated::class, 2);
    }

    /**
     * @param  Closure(Agent, User, string, RunOptions): AgentExecution  $handler
     */
    private function stubRunner(Closure $handler): void
    {
        NotificationStubRunner::$handler = $handler;

        $this->app->instance(AgentRunner::class, new NotificationStubRunner(
            app(AgentRuntimeFactory::class),
            app(ProviderRegistry::class),
            app(UsageRecorder::class),
            app(Settings::class),
        ));
    }

    private function completedExecution(): AgentExecution
    {
        return AgentExecution::factory()->for($this->user)->for($this->agent)->create([
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::COMPLETED),
            'meta' => ['output' => 'Concluído.'],
        ]);
    }
}

class NotificationStubRunner extends AgentRunner
{
    public static ?Closure $handler = null;

    public function run(Agent $agent, User $user, string $prompt, RunOptions $options): AgentExecution
    {
        return (self::$handler)($agent, $user, $prompt, $options);
    }
}
