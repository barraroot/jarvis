<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Tool;
use App\Models\ToolType;
use Database\Seeders\WhatsappToolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesWuzapi;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class WhatsappToolCatalogTest extends TestCase
{
    use FakesWuzapi;
    use RefreshDatabase;
    use SeedsLookups;

    /**
     * CT-30: identifier => [is_write, is_sensitive].
     *
     * @var array<string, array{0: bool, 1: bool}>
     */
    private const array CATALOGUE = [
        'whatsapp.session.status' => [false, false],
        'whatsapp.chats.list' => [false, false],
        'whatsapp.messages.read' => [false, false],
        'whatsapp.contacts.list' => [false, false],
        'whatsapp.groups.list' => [false, false],
        'whatsapp.groups.info' => [false, false],
        'whatsapp.groups.invite_link' => [false, true],
        'whatsapp.media.download' => [false, false],
        'whatsapp.messages.mark_read' => [true, false],
        'whatsapp.send.text' => [true, false],
        'whatsapp.send.image' => [true, false],
        'whatsapp.send.video' => [true, false],
        'whatsapp.send.audio' => [true, false],
        'whatsapp.send.document' => [true, false],
        'whatsapp.send.location' => [true, false],
        'whatsapp.groups.create' => [true, false],
        'whatsapp.groups.update' => [true, false],
        'whatsapp.groups.participants' => [true, false],
        'whatsapp.groups.leave' => [true, false],
        'whatsapp.schedule.create' => [true, false],
        'whatsapp.schedule.list' => [false, false],
        'whatsapp.schedule.cancel' => [true, false],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeWuzapi();

        $this->setUpSeedsLookups();
    }

    public function test_sync_registers_exactly_the_catalogue_with_its_flags(): void
    {
        $this->seed(WhatsappToolSeeder::class);

        $tools = Tool::query()->where('identifier', 'like', 'whatsapp.%')->get()->keyBy('identifier');

        $this->assertCount(22, self::CATALOGUE);
        $this->assertEqualsCanonicalizing(array_keys(self::CATALOGUE), $tools->keys()->all());

        foreach (self::CATALOGUE as $identifier => [$isWrite, $isSensitive]) {
            $tool = $tools[$identifier];

            $this->assertSame(ToolType::idFor(ToolType::INTERNAL), $tool->tool_type_id, $identifier);
            $this->assertSame($isWrite, $tool->is_write, "{$identifier} is_write");
            $this->assertSame($isSensitive, $tool->is_sensitive, "{$identifier} is_sensitive");
            $this->assertNotEmpty($tool->input_schema, $identifier);
            $this->assertNotEmpty($tool->output_schema, $identifier);
        }
    }

    public function test_tools_are_linked_only_to_personal_assistant_and_communication(): void
    {
        $personalAssistant = Agent::factory()->default()->create(['slug' => 'personal-assistant']);
        $communication = Agent::factory()->create(['slug' => 'communication']);
        $research = Agent::factory()->create(['slug' => 'research']);

        $this->seed(WhatsappToolSeeder::class);

        $toolIds = Tool::query()->where('identifier', 'like', 'whatsapp.%')->pluck('id')->sort()->values()->all();

        foreach ([$personalAssistant, $communication] as $agent) {
            $linked = $agent->tools()->where('identifier', 'like', 'whatsapp.%')->pluck('tools.id')->sort()->values()->all();

            $this->assertSame($toolIds, $linked, $agent->slug);
        }

        $this->assertFalse($research->tools()->where('identifier', 'like', 'whatsapp.%')->exists());
    }

    public function test_second_run_neither_creates_nor_changes_rows(): void
    {
        $communication = Agent::factory()->create(['slug' => 'communication']);
        $existing = Tool::factory()->internal()->create(['identifier' => 'custom.read']);
        $communication->tools()->attach($existing->id);

        $this->seed(WhatsappToolSeeder::class);

        $before = Tool::query()->orderBy('id')->get(['id', 'identifier', 'updated_at'])->toArray();
        $links = $communication->tools()->count();

        $this->travel(5)->seconds();
        $this->seed(WhatsappToolSeeder::class);

        $this->assertSame($before, Tool::query()->orderBy('id')->get(['id', 'identifier', 'updated_at'])->toArray());
        $this->assertSame($links, $communication->tools()->count());
        $this->assertTrue($communication->tools()->whereKey($existing->id)->exists());
    }
}
