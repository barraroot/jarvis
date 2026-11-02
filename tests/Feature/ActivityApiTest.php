<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\Approval;
use App\Models\Conversation;
use App\Models\Tool;
use App\Models\ToolExecution;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class ActivityApiTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));

        $this->user = User::factory()->create();

        $this->actingAs($this->user);
    }

    public function test_returns_only_own_activity_filtered_by_type_and_period(): void
    {
        $agent = Agent::factory()->create(['name' => 'Pesquisador']);
        $tool = Tool::factory()->create(['name' => 'Enviar e-mail']);
        $conversation = Conversation::factory()->for($this->user)->create();

        $execution = AgentExecution::factory()->for($this->user)->for($agent)->completed()->create([
            'conversation_id' => $conversation->id,
            'started_at' => '2026-11-14 10:00:00',
            'finished_at' => '2026-11-14 10:01:00',
        ]);
        $toolRun = ToolExecution::factory()->for($this->user)->for($tool)->create([
            'agent_execution_id' => $execution->id,
            'started_at' => '2026-11-14 10:00:30',
        ]);
        $approval = Approval::factory()->approved()->for($this->user)->for($tool)->create([
            'agent_execution_id' => $execution->id,
            'requested_at' => '2026-11-10 09:00:00',
            'decided_at' => '2026-11-10 09:05:00',
        ]);
        $old = AgentExecution::factory()->for($this->user)->for($agent)->failed()->create([
            'started_at' => '2026-10-01 08:00:00',
        ]);

        // Another operator's activity never leaks.
        $other = User::factory()->create();
        $foreignExecution = AgentExecution::factory()->for($other)->for($agent)->completed()->create(['started_at' => '2026-11-14 11:00:00']);
        $foreignTool = ToolExecution::factory()->for($other)->for($tool)->create(['started_at' => '2026-11-14 11:00:00']);
        $foreignApproval = Approval::factory()->pending()->for($other)->for($tool)->create(['requested_at' => '2026-11-14 11:00:00']);

        // Whole timeline, newest first, with links to conversation.
        $response = $this->getJson('/api/v1/activity')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 4);

        $this->assertSame(
            [['tool', $toolRun->id], ['agent', $execution->id], ['approval', $approval->id], ['agent', $old->id]],
            collect($response->json('data'))->map(fn (array $entry): array => [$entry['type'], $entry['id']])->all(),
        );

        $response
            ->assertJsonPath('data.0.title', 'Enviar e-mail')
            ->assertJsonPath('data.0.conversation_id', $conversation->id)
            ->assertJsonPath('data.1.title', 'Pesquisador')
            ->assertJsonPath('data.1.status', 'completed')
            ->assertJsonPath('data.2.status', 'approved')
            ->assertJsonPath('data.2.conversation_id', $conversation->id);

        $entries = collect($response->json('data'))->map(fn (array $entry): string => $entry['type'].':'.$entry['id'])->all();
        $this->assertNotContains('agent:'.$foreignExecution->id, $entries);
        $this->assertNotContains('tool:'.$foreignTool->id, $entries);
        $this->assertNotContains('approval:'.$foreignApproval->id, $entries);

        // Type filter.
        $this->getJson('/api/v1/activity?type=agent')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $execution->id)
            ->assertJsonPath('data.1.id', $old->id);

        $this->getJson('/api/v1/activity?type=approval')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'approval');

        // Period filter (inclusive days).
        $this->getJson('/api/v1/activity?from=2026-11-10&to=2026-11-13')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $approval->id);

        $this->getJson('/api/v1/activity?from=2026-11-01&type=agent')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $execution->id);

        // Validation.
        $this->getJson('/api/v1/activity?type=memory')->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->getJson('/api/v1/activity?from=2026-11-10&to=2026-11-01')->assertUnprocessable()->assertJsonValidationErrors('to');

        // Pagination at 20 per page.
        ToolExecution::factory()->count(20)->for($this->user)->for($tool)->create(['started_at' => '2026-11-01 00:00:00']);

        $this->getJson('/api/v1/activity?page=2')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 24);
    }
}
