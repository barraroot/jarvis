<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Tool;
use App\Models\ToolType;
use App\Tools\Internal\CurrentTimeTool;
use App\Tools\Internal\UpdateVisualizationSectionTool;
use App\Tools\InternalToolRegistry;
use Database\Seeders\VisualizationToolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class InternalToolSyncTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();
    }

    public function test_sync_is_idempotent(): void
    {
        $expected = app(InternalToolRegistry::class)->all();

        $this->artisan('jarvis:tools:sync-internal')->assertSuccessful();

        $this->assertDatabaseCount('tools', count($expected));

        $this->assertDatabaseHas('tools', [
            'identifier' => 'time.current',
            'tool_type_id' => ToolType::idFor(ToolType::INTERNAL),
            'handler_class' => CurrentTimeTool::class,
            'is_write' => false,
            'is_sensitive' => false,
            'is_active' => true,
        ]);

        $tool = Tool::query()->where('identifier', 'time.current')->firstOrFail();
        $this->assertSame('Hora atual', $tool->name);
        $this->assertSame(30, $tool->timeout_seconds);

        // A second run converges to the same rows without touching them.
        $this->travel(5)->seconds();
        $this->artisan('jarvis:tools:sync-internal')->assertSuccessful();

        $this->assertDatabaseCount('tools', count($expected));
        $this->assertSame($tool->fresh()->updated_at->getTimestamp(), $tool->updated_at->getTimestamp());

        // Stale metadata is refreshed back to the class definition.
        $tool->update(['name' => 'Nome velho', 'description' => 'Descrição velha']);

        $this->artisan('jarvis:tools:sync-internal')->assertSuccessful();

        $tool->refresh();

        $this->assertSame('Hora atual', $tool->name);
        $this->assertSame(CurrentTimeTool::class, $tool->handler_class);
    }

    public function test_sync_registers_visualization_tool_without_approval(): void
    {
        $this->artisan('jarvis:tools:sync-internal')->assertSuccessful();

        $this->assertDatabaseHas('tools', [
            'identifier' => 'visualization.update_section',
            'tool_type_id' => ToolType::idFor(ToolType::INTERNAL),
            'handler_class' => UpdateVisualizationSectionTool::class,
            'is_write' => false,
            'is_sensitive' => false,
            'is_active' => true,
        ]);

        $tool = Tool::query()->where('identifier', 'visualization.update_section')->firstOrFail();
        $this->assertFalse($tool->requiresApproval());
        $this->assertFalse($tool->input_schema['additionalProperties']);
        $this->assertFalse($tool->input_schema['properties']['section']['additionalProperties']);
    }

    public function test_visualization_tool_seeder_links_every_agent_without_detaching(): void
    {
        $agents = Agent::factory()->count(2)->create();
        $existing = Tool::factory()->internal()->create(['identifier' => 'custom.read']);
        $agents[0]->tools()->attach($existing->id);

        $this->seed(VisualizationToolSeeder::class);
        $this->seed(VisualizationToolSeeder::class);

        $tool = Tool::query()->where('identifier', 'visualization.update_section')->firstOrFail();

        foreach ($agents as $agent) {
            $this->assertTrue($agent->tools()->whereKey($tool->id)->exists());
        }

        // Links configured in the admin are preserved and nothing is duplicated.
        $this->assertTrue($agents[0]->tools()->whereKey($existing->id)->exists());
        $this->assertSame(1, $agents[1]->tools()->whereKey($tool->id)->count());
    }
}
