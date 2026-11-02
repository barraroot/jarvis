<?php

namespace Tests\Feature;

use App\Events\Agent\Completed;
use App\Events\Agent\Failed;
use App\Events\Agent\Progress;
use App\Events\Agent\Started;
use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class AgentEventsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    public function test_agent_events_broadcast_with_expected_names(): void
    {
        $this->setUpSeedsLookups();

        Event::fake();

        $user = User::factory()->create();
        $agent = Agent::factory()->create();

        $started = AgentExecution::factory()->for($user)->for($agent)->running()->create();
        $progress = AgentExecution::factory()->for($user)->for($agent)->running()->create();
        $completed = AgentExecution::factory()->for($user)->for($agent)->completed()->create();
        $failed = AgentExecution::factory()->for($user)->for($agent)->failed()->create();

        Started::dispatch($user, $started);
        Progress::dispatch($user, $progress, 'Chamando ferramenta time.current');
        Completed::dispatch($user, $completed);
        Failed::dispatch($user, $failed);

        Event::assertDispatched(Started::class, function (Started $event) use ($user, $started): bool {
            return $event->broadcastAs() === 'agent.started'
                && $event->broadcastOn()->name === 'private-user.'.$user->id
                && $event->broadcastWith() === [
                    'execution_id' => $started->id,
                    'agent' => $started->agent->slug,
                    'depth' => 0,
                    'parent_id' => null,
                    'status' => 'running',
                    'progress_note' => null,
                ];
        });

        Event::assertDispatched(Progress::class, fn (Progress $event): bool => $event->broadcastAs() === 'agent.progress'
            && $event->broadcastWith()['progress_note'] === 'Chamando ferramenta time.current'
            && $event->broadcastWith()['execution_id'] === $progress->id);

        Event::assertDispatched(Completed::class, fn (Completed $event): bool => $event->broadcastAs() === 'agent.completed'
            && $event->broadcastWith()['status'] === 'completed'
            && $event->broadcastWith()['execution_id'] === $completed->id);

        Event::assertDispatched(Failed::class, fn (Failed $event): bool => $event->broadcastAs() === 'agent.failed'
            && $event->broadcastWith()['status'] === 'failed'
            && $event->broadcastWith()['execution_id'] === $failed->id);
    }
}
