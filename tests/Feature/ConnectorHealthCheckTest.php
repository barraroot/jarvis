<?php

namespace Tests\Feature;

use App\Events\Connector\StatusChanged;
use App\Events\NotificationCreated;
use App\Models\ConnectorStatus;
use App\Models\McpServer;
use App\Models\Notification;
use App\Models\NotificationType;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesMcpServer;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class ConnectorHealthCheckTest extends TestCase
{
    use FakesMcpServer;
    use RefreshDatabase;
    use SeedsLookups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();
    }

    public function test_status_transition_broadcasts_and_notifies_on_error(): void
    {
        Event::fake([StatusChanged::class, NotificationCreated::class]);

        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $inactive = User::factory()->create(['is_active' => false]);

        $global = McpServer::factory()->global()->connected()->create([
            'url' => 'https://erp.example.com/mcp',
            'last_checked_at' => now()->subMinutes(10),
        ]);
        $personal = McpServer::factory()->errored()->for($alice)->create([
            'url' => 'https://crm.example.com/mcp',
            'last_checked_at' => now()->subMinutes(10),
        ]);

        Http::fake([
            'https://erp.example.com/mcp' => fn () => throw new ConnectionException('Connection refused'),
        ]);
        $this->fakeMcpServer('https://crm.example.com/mcp');

        $this->artisan('jarvis:connectors:health-check')->assertSuccessful();

        $global->refresh();
        $personal->refresh();

        $this->assertSame(ConnectorStatus::ERROR, $global->status->slug);
        $this->assertStringContainsString('Connection refused', $global->last_error);
        $this->assertTrue($global->last_checked_at->isAfter(now()->subMinute()));

        $this->assertSame(ConnectorStatus::CONNECTED, $personal->status->slug);
        $this->assertNull($personal->last_error);

        // Global: every active user's channel; personal: only the owner's.
        Event::assertDispatched(StatusChanged::class, function (StatusChanged $event) use ($global, $alice, $bob): bool {
            $channels = collect($event->broadcastOn())->map(fn (PrivateChannel $channel): string => $channel->name)->sort()->values()->all();

            return $event->server->is($global)
                && $event->broadcastAs() === 'connector.status.changed'
                && $event->broadcastWith()['status'] === ConnectorStatus::ERROR
                && $channels === collect(["private-user.{$alice->id}", "private-user.{$bob->id}"])->sort()->values()->all();
        });
        Event::assertDispatched(StatusChanged::class, fn (StatusChanged $event): bool => $event->server->is($personal)
            && $event->previousStatus === ConnectorStatus::ERROR
            && $event->broadcastOn()[0]->name === "private-user.{$alice->id}"
            && count($event->broadcastOn()) === 1);

        $errorNotifications = Notification::query()
            ->where('notification_type_id', NotificationType::idFor(NotificationType::CONNECTOR_ERROR))
            ->pluck('user_id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(collect([$alice->id, $bob->id])->sort()->values()->all(), $errorNotifications);
        $this->assertFalse(Notification::query()->where('user_id', $inactive->id)->exists());

        $this->assertTrue(collect(app(Schedule::class)->events())->contains(
            fn (ScheduledEvent $event): bool => str_contains($event->command, 'jarvis:connectors:health-check'),
        ));
    }

    public function test_unchanged_status_does_not_broadcast(): void
    {
        Event::fake([StatusChanged::class, NotificationCreated::class]);

        $user = User::factory()->create();

        $due = McpServer::factory()->connected()->for($user)->create([
            'url' => 'https://erp.example.com/mcp',
            'last_checked_at' => now()->subMinutes(6),
        ]);
        $recent = McpServer::factory()->connected()->for($user)->create([
            'url' => 'https://erp.example.com/mcp',
            'last_checked_at' => now()->subMinute(),
        ]);
        McpServer::factory()->connected()->inactive()->for($user)->create(['url' => 'https://erp.example.com/mcp']);

        $this->fakeMcpServer('https://erp.example.com/mcp');

        $this->artisan('jarvis:connectors:health-check')->assertSuccessful();

        $this->assertTrue($due->fresh()->last_checked_at->isAfter(now()->subMinute()));
        $this->assertTrue($recent->fresh()->last_checked_at->isBefore(now()->subSeconds(50)));
        $this->assertSame(ConnectorStatus::CONNECTED, $due->fresh()->status->slug);

        Event::assertNotDispatched(StatusChanged::class);
        Event::assertNotDispatched(NotificationCreated::class);
        $this->assertSame(0, Notification::query()->count());
    }
}
