<?php

namespace Tests\Feature;

use App\Events\Visualization\VisualizationUpdated;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Visualization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class VisualizationApiTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private const string CURRENT = '/api/v1/visualizations/current';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        Event::fake([VisualizationUpdated::class]);

        $this->user = User::factory()->create();
    }

    public function test_current_returns_latest_open_of_owner(): void
    {
        Visualization::factory()->for($this->user)->create(['updated_at' => now()->subMinutes(10)]);
        $latest = Visualization::factory()->for($this->user)->create([
            'title' => 'Mais recente',
            'updated_at' => now()->subMinute(),
            'sections' => [
                ['key' => 'results', 'position' => 2, 'label' => 'RESULTADOS', 'type' => 'results', 'status' => 'syncing', 'items' => [
                    ['badges' => ['best'], 'subtitle' => 'b', 'title' => 'a'],
                ]],
                ['headline' => 'h', 'type' => 'question', 'label' => 'PERGUNTA', 'position' => 1, 'key' => 'question'],
            ],
        ]);
        Visualization::factory()->for($this->user)->closed()->create(['updated_at' => now()]);

        // Another user's newer open visualization never leaks.
        Visualization::factory()->for(User::factory())->create(['updated_at' => now()->addMinute()]);

        $response = $this->actingAs($this->user)->getJson(self::CURRENT);

        $response->assertOk()
            ->assertJsonPath('data.id', $latest->id)
            ->assertJsonPath('data.title', 'Mais recente')
            ->assertJsonPath('data.status', 'syncing')
            ->assertJsonPath('data.closed_at', null)
            ->assertJsonPath('data.task_id', $latest->task_id);

        // Sections ordered by position, fields in the CT-05 order.
        $this->assertSame([
            ['key' => 'question', 'position' => 1, 'label' => 'PERGUNTA', 'type' => 'question', 'headline' => 'h'],
            ['key' => 'results', 'position' => 2, 'label' => 'RESULTADOS', 'type' => 'results', 'status' => 'syncing', 'items' => [
                ['title' => 'a', 'subtitle' => 'b', 'badges' => ['best']],
            ]],
        ], $response->json('data.sections'));

        $this->assertSame(
            ['id', 'task_id', 'title', 'source', 'status', 'created_at', 'updated_at', 'closed_at', 'sections'],
            array_keys($response->json('data')),
        );
    }

    public function test_current_returns_null_when_none_open(): void
    {
        Visualization::factory()->for($this->user)->closed()->create();
        Visualization::factory()->for(User::factory())->create();

        $this->actingAs($this->user)->getJson(self::CURRENT)
            ->assertOk()
            ->assertExactJson(['data' => null]);
    }

    public function test_current_requires_authentication(): void
    {
        $this->getJson(self::CURRENT)->assertUnauthorized();
    }

    public function test_close_is_idempotent_and_keeps_task_running(): void
    {
        $task = Task::factory()->running()->for($this->user)->create();
        $visualization = Visualization::factory()->for($this->user)->create(['task_id' => $task->id]);
        $task->update(['visualization_id' => $visualization->id]);
        $taskBefore = $task->fresh()->only(['task_status_id', 'progress', 'completed_at', 'updated_at', 'visualization_id']);

        $first = $this->actingAs($this->user)->postJson($this->closeUrl($visualization));

        $first->assertOk()->assertJsonPath('data.id', $visualization->id);
        $closedAt = $first->json('data.closed_at');
        $this->assertNotNull($closedAt);
        $this->assertNotNull($visualization->fresh()->closed_at);

        $this->travel(5)->seconds();

        $this->actingAs($this->user)->postJson($this->closeUrl($visualization))
            ->assertOk()
            ->assertJsonPath('data.closed_at', $closedAt);

        $task->refresh();
        $this->assertSame(TaskStatus::RUNNING, $task->status->slug);
        $this->assertEquals($taskBefore, $task->only(['task_status_id', 'progress', 'completed_at', 'updated_at', 'visualization_id']));

        // Closed is no longer current.
        $this->actingAs($this->user)->getJson(self::CURRENT)->assertExactJson(['data' => null]);
    }

    public function test_close_foreign_returns_403(): void
    {
        $foreign = Visualization::factory()->for(User::factory())->create();

        $this->actingAs($this->user)->postJson($this->closeUrl($foreign))->assertForbidden();

        $this->assertNull($foreign->fresh()->closed_at);
    }

    public function test_close_missing_returns_404(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/visualizations/999999/close')->assertNotFound();
    }

    private function closeUrl(Visualization $visualization): string
    {
        return "/api/v1/visualizations/{$visualization->id}/close";
    }
}
