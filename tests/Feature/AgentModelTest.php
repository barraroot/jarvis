<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AiModel;
use App\Models\Tool;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class AgentModelTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    public function test_effective_model_falls_back_to_global_default(): void
    {
        $default = AiModel::factory()->defaultText()->create();
        $own = AiModel::factory()->create();

        $withoutModel = Agent::factory()->create(['ai_model_id' => null]);
        $withModel = Agent::factory()->create(['ai_model_id' => $own->id]);

        $this->assertTrue($withoutModel->effectiveModel()->is($default));
        $this->assertTrue($withModel->effectiveModel()->is($own));
    }

    public function test_effective_model_throws_when_no_default_exists(): void
    {
        $agent = Agent::factory()->create();

        $this->expectException(ModelNotFoundException::class);

        $agent->effectiveModel();
    }

    public function test_tools_pivot_records_created_at_only(): void
    {
        $agent = Agent::factory()->default()->create();
        $tool = Tool::factory()->create();

        $agent->tools()->attach($tool->id);

        $attached = $agent->tools()->first();

        $this->assertTrue($agent->isDefault());
        $this->assertNotNull($attached->pivot->created_at);
        $this->assertTrue($tool->agents->contains($agent));
    }

    public function test_active_scope_excludes_inactive_agents(): void
    {
        $active = Agent::factory()->create();
        Agent::factory()->inactive()->create();

        $this->assertSame([$active->id], Agent::active()->pluck('id')->all());
    }
}
