<?php

namespace Tests\Feature;

use App\Models\Tool;
use App\Models\ToolType;
use App\Tools\Internal\CurrentTimeTool;
use App\Tools\InternalToolRegistry;
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
}
