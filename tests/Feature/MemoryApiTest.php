<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Memory;
use App\Models\MemoryEmbedding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class MemoryApiTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->user = User::factory()->create();
        AiModel::factory()->defaultEmbedding()->create();

        Embeddings::fake();

        $this->actingAs($this->user);
    }

    public function test_list_filters_by_status_and_search(): void
    {
        $confirmed = Memory::factory()->confirmed()->for($this->user)->create(['content' => 'Prefere café preto']);
        Memory::factory()->hypothesis()->for($this->user)->create(['content' => 'Talvez goste de chá']);
        Memory::factory()->confirmed()->for(User::factory())->create(['content' => 'Prefere café preto também']);

        $this->getJson('/api/v1/memories?status=confirmed&q=café')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $confirmed->id)
            ->assertJsonPath('summary.confirmed', 1)
            ->assertJsonPath('summary.hypotheses', 1);
    }

    public function test_store_creates_confirmed_memory_with_embedding(): void
    {
        $response = $this->postJson('/api/v1/memories', [
            'content' => 'Está desenvolvendo o JARVIS',
            'category' => 'project',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.content', 'Está desenvolvendo o JARVIS')
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.category', 'project')
            ->assertJsonPath('data.confirmed_at', fn ($value) => $value !== null);

        $memory = Memory::query()->firstOrFail();

        $this->assertTrue($memory->isConfirmed());
        $this->assertTrue($memory->embedding()->exists());
    }

    public function test_edit_regenerates_embedding(): void
    {
        $memory = Memory::factory()->confirmed()->for($this->user)->create(['content' => 'Conteúdo original']);
        MemoryEmbedding::factory()->for($memory)->for(AiModel::query()->firstOrFail(), 'model')->vector([1.0, 0.0, 0.0])->create();

        $original = $memory->embedding->embedding->toArray();

        $this->patchJson("/api/v1/memories/{$memory->id}", ['content' => 'Conteúdo editado'])
            ->assertOk()
            ->assertJsonPath('data.content', 'Conteúdo editado');

        $fresh = $memory->fresh();

        $this->assertSame('Conteúdo editado', $fresh->content);
        $this->assertNotSame($original, $fresh->embedding->embedding->toArray());
        $this->assertSame(1, MemoryEmbedding::query()->count());
    }

    public function test_delete_is_hard_delete(): void
    {
        $memory = Memory::factory()->confirmed()->for($this->user)->create();
        MemoryEmbedding::factory()->for($memory)->for(AiModel::query()->firstOrFail(), 'model')->vector([1.0, 0.0, 0.0])->create();

        $this->deleteJson("/api/v1/memories/{$memory->id}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('memories', ['id' => $memory->id]);
        $this->assertDatabaseMissing('memory_embeddings', ['memory_id' => $memory->id]);
    }

    public function test_confirm_and_discard_hypothesis(): void
    {
        $toConfirm = Memory::factory()->hypothesis()->for($this->user)->create();
        $toDiscard = Memory::factory()->hypothesis()->for($this->user)->create();

        $this->postJson("/api/v1/memories/{$toConfirm->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $confirmed = $toConfirm->fresh();

        $this->assertTrue($confirmed->isConfirmed());
        $this->assertNotNull($confirmed->confirmed_at);

        $this->postJson("/api/v1/memories/{$toDiscard->id}/discard")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('memories', ['id' => $toDiscard->id]);
        $this->assertSame(1, Memory::query()->count());
    }

    public function test_cannot_touch_other_users_memory(): void
    {
        $stranger = User::factory()->create();
        $foreign = Memory::factory()->confirmed()->for($stranger)->create();
        MemoryEmbedding::factory()->for($foreign)->for(AiModel::query()->firstOrFail(), 'model')->vector([1.0, 0.0, 0.0])->create();

        $this->getJson('/api/v1/memories')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->patchJson("/api/v1/memories/{$foreign->id}", ['content' => 'Invasão'])
            ->assertForbidden();

        $this->deleteJson("/api/v1/memories/{$foreign->id}")->assertForbidden();
        $this->postJson("/api/v1/memories/{$foreign->id}/confirm")->assertForbidden();
        $this->postJson("/api/v1/memories/{$foreign->id}/discard")->assertForbidden();

        $this->assertDatabaseHas('memories', ['id' => $foreign->id, 'content' => $foreign->content]);
    }
}
