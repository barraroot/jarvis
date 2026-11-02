<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\ExecutionStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class AgentExecutionApiTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->user = User::factory()->create();

        $this->actingAs($this->user);
    }

    public function test_lists_only_own_executions_with_children(): void
    {
        $agent = Agent::factory()->create();
        $otherAgent = Agent::factory()->create();

        $root = AgentExecution::factory()->for($this->user)->for($agent)->completed()->create(['depth' => 0]);
        $child = AgentExecution::factory()->for($this->user)->for($otherAgent)->completed()->childOf($root)->create();

        $otherUser = User::factory()->create();
        $foreign = AgentExecution::factory()->for($otherUser)->for($agent)->completed()->create();

        // History: finished top-level executions only; the foreign one never appears.
        $this->getJson('/api/v1/agent-executions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $root->id)
            ->assertJsonMissing(['id' => $foreign->id]);

        // The delegation chain nests under its parent, never at the top level.
        $this->getJson('/api/v1/agent-executions')
            ->assertOk()
            ->assertJsonCount(1, 'data.0.children')
            ->assertJsonPath('data.0.children.0.id', $child->id)
            ->assertJsonPath('data.0.children.0.depth', 1)
            ->assertJsonPath('data.0.children.0.parent_id', $root->id)
            ->assertJsonPath('data.0.children.0.agent.slug', $otherAgent->slug)
            ->assertJsonPath('data.0.duration_ms', fn (int $duration): bool => $duration >= 0);
    }

    public function test_active_filter_returns_running_executions_only(): void
    {
        $agent = Agent::factory()->create();

        $running = AgentExecution::factory()->for($this->user)->for($agent)->running()->create();
        AgentExecution::factory()->for($this->user)->for($agent)->completed()->create();
        AgentExecution::factory()->for($this->user)->for($agent)->failed()->create();

        $this->getJson('/api/v1/agent-executions?active=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $running->id)
            ->assertJsonPath('data.0.status', ExecutionStatus::RUNNING);
    }

    public function test_agents_endpoint_lists_active_agents(): void
    {
        $default = Agent::factory()->default()->create();
        $active = Agent::factory()->create(['slug' => 'research']);
        Agent::factory()->inactive()->create(['slug' => 'inactive-agent']);

        $this->getJson('/api/v1/agents')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', $default->slug)
            ->assertJsonPath('data.0.is_default', true)
            ->assertJsonPath('data.1.slug', $active->slug)
            ->assertJsonMissing(['slug' => 'inactive-agent']);
    }
}
