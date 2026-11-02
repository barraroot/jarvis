<?php

namespace Tests\Feature;

use App\Events\Tool\ToolCompleted;
use App\Events\Tool\ToolFailed;
use App\Events\Tool\ToolStarted;
use App\Models\ExecutionStatus;
use App\Models\Tool;
use App\Models\ToolExecution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class ToolEventsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    public function test_tool_events_are_broadcast_on_private_channel(): void
    {
        $this->setUpSeedsLookups();

        Event::fake();

        $user = User::factory()->create();
        $tool = Tool::factory()->create();
        $execution = ToolExecution::factory()->for($user)->for($tool)->running()->create();

        ToolStarted::dispatch($user, $tool, $execution);
        ToolCompleted::dispatch($user, $tool, $execution);
        ToolFailed::dispatch($user, $tool, $execution->fresh());

        foreach ([ToolStarted::class, ToolCompleted::class, ToolFailed::class] as $eventClass) {
            Event::assertDispatched($eventClass, fn ($event): bool => $event->broadcastOn()->name === 'private-user.'.$user->id);
        }

        Event::assertDispatched(ToolStarted::class, fn (ToolStarted $event): bool => $event->broadcastAs() === 'tool.started'
            && $event->broadcastWith() === [
                'tool' => $tool->identifier,
                'execution_id' => $execution->id,
                'status' => 'running',
            ]);

        Event::assertDispatched(ToolCompleted::class, fn (ToolCompleted $event): bool => $event->broadcastAs() === 'tool.completed'
            && $event->broadcastWith()['status'] === 'completed');

        Event::assertDispatched(ToolFailed::class, fn (ToolFailed $event): bool => $event->broadcastAs() === 'tool.failed'
            && $event->broadcastWith()['status'] === ExecutionStatus::RUNNING);
    }
}
