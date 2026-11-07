<?php

namespace Tests\Feature;

use App\Models\AiModelCapability;
use App\Models\LookupModel;
use App\Models\VisualizationStatus;
use Database\Seeders\LookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LookupSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<string, int>
     */
    private array $expectedCounts = [
        'message_roles' => 4,
        'message_sources' => 2,
        'result_types' => 9,
        'execution_statuses' => 7,
        'task_statuses' => 7,
        'approval_statuses' => 4,
        'memory_statuses' => 2,
        'memory_categories' => 5,
        'tool_types' => 4,
        'connector_statuses' => 4,
        'ai_provider_drivers' => 13,
        'ai_model_capabilities' => 3,
        'notification_types' => 8,
        'audit_actions' => 40,
        'log_levels' => 4,
        'visualization_statuses' => 3,
        'whatsapp_outbound_statuses' => 4,
        'whatsapp_schedule_statuses' => 4,
    ];

    public function test_seeders_are_idempotent_and_seed_expected_slugs(): void
    {
        $this->seed(LookupSeeder::class);
        $this->seed(LookupSeeder::class);

        foreach ($this->expectedCounts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "Unexpected row count for [{$table}].");
        }

        $this->assertDatabaseHas('task_statuses', ['slug' => 'waiting_approval']);
        $this->assertDatabaseHas('memory_statuses', ['slug' => 'hypothesis']);
        $this->assertDatabaseHas('execution_statuses', ['slug' => 'rate_limited']);
        $this->assertDatabaseHas('notification_types', ['slug' => 'approval_pending']);
    }

    public function test_voice_lookups_are_seeded(): void
    {
        $this->seed(LookupSeeder::class);
        LookupModel::flushCache();

        $this->assertDatabaseHas('ai_model_capabilities', ['slug' => AiModelCapability::REALTIME, 'name' => 'Realtime', 'is_active' => true]);

        foreach ([VisualizationStatus::SYNCING, VisualizationStatus::UPDATED, VisualizationStatus::FAILED] as $slug) {
            $this->assertDatabaseHas('visualization_statuses', ['slug' => $slug, 'is_active' => true]);
            $this->assertIsInt(VisualizationStatus::idFor($slug));
        }
    }

    public function test_seeded_rows_are_active_and_named(): void
    {
        $this->seed(LookupSeeder::class);

        $this->assertDatabaseHas('ai_provider_drivers', ['slug' => 'openai', 'name' => 'OpenAI', 'is_active' => true]);
        $this->assertDatabaseHas('tool_types', ['slug' => 'mcp', 'name' => 'MCP']);
        $this->assertSame(0, DB::table('audit_actions')->where('is_active', false)->count());
    }

    public function test_reseeding_keeps_existing_ids(): void
    {
        $this->seed(LookupSeeder::class);
        $originalId = DB::table('task_statuses')->where('slug', 'running')->value('id');

        $this->seed(LookupSeeder::class);

        $this->assertSame($originalId, DB::table('task_statuses')->where('slug', 'running')->value('id'));
    }
}
