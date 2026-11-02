<?php

namespace Tests\Feature;

use App\Exceptions\SensitiveMemoryRejectedException;
use App\Models\AiModel;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\MemoryEmbedding;
use App\Models\MemoryStatus;
use App\Models\Message;
use App\Models\User;
use App\Services\MemoryService;
use Database\Factories\MemoryEmbeddingFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Pgvector\Laravel\Vector;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class MemoryServiceTest extends TestCase
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
    }

    public function test_near_duplicate_updates_existing_instead_of_creating(): void
    {
        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => [
            MemoryEmbeddingFactory::fakeVector($prompt->contains('preto') ? [1.0, 0.0, 0.0] : [1.0, 0.001, 0.0]),
        ]);

        $service = app(MemoryService::class);

        $first = $service->remember($this->user, 'Gosta de café preto', 'preference', MemoryStatus::CONFIRMED);
        $second = $service->remember($this->user, 'Gosta de café preto sem açúcar', 'preference', MemoryStatus::HYPOTHESIS);

        $this->assertSame(1, Memory::query()->count());
        $this->assertSame($first->id, $second->id);
        $this->assertSame('Gosta de café preto sem açúcar', $second->content);
        $this->assertTrue($second->isConfirmed(), 'The strongest status is kept.');
        $this->assertNotNull($second->confirmed_at);
        $this->assertSame(1, MemoryEmbedding::query()->count());
    }

    public function test_update_regenerates_embedding(): void
    {
        Embeddings::fake([
            [MemoryEmbeddingFactory::fakeVector([1.0, 0.0, 0.0])],
            [MemoryEmbeddingFactory::fakeVector([0.0, 1.0, 0.0])],
        ]);

        $service = app(MemoryService::class);

        $memory = $service->remember($this->user, 'Conteúdo original', 'fact', MemoryStatus::CONFIRMED);

        $originalEmbedding = $memory->embedding->embedding;

        $this->assertInstanceOf(Vector::class, $originalEmbedding);

        $updated = $service->update($memory, 'Conteúdo editado');

        $this->assertSame('Conteúdo editado', $updated->content);
        $this->assertNotSame($originalEmbedding->toArray(), $updated->embedding->embedding->toArray());
        $this->assertSame(1, MemoryEmbedding::query()->count());
    }

    public function test_delete_removes_memory_and_embedding(): void
    {
        Embeddings::fake();

        $service = app(MemoryService::class);

        $memory = $service->remember($this->user, 'Será excluída', 'fact', MemoryStatus::CONFIRMED);

        $this->assertDatabaseHas('memory_embeddings', ['memory_id' => $memory->id]);

        $service->delete($memory, $this->user);

        $this->assertDatabaseMissing('memories', ['id' => $memory->id]);
        $this->assertDatabaseMissing('memory_embeddings', ['memory_id' => $memory->id]);
        $this->assertSame(0, Memory::query()->count());
    }

    public function test_confirm_sets_confirmed_at(): void
    {
        Embeddings::fake();

        $service = app(MemoryService::class);

        $memory = $service->remember($this->user, 'Hipótese sobre o usuário', 'context', MemoryStatus::HYPOTHESIS);

        $this->assertFalse($memory->isConfirmed());
        $this->assertNull($memory->confirmed_at);

        $confirmed = $service->confirm($memory);

        $this->assertTrue($confirmed->isConfirmed());
        $this->assertNotNull($confirmed->confirmed_at);
        $this->assertDatabaseHas('memories', [
            'id' => $memory->id,
            'memory_status_id' => MemoryStatus::idFor(MemoryStatus::CONFIRMED),
        ]);
    }

    public function test_sensitive_hypothesis_is_rejected_but_explicit_confirmed_is_allowed(): void
    {
        Embeddings::fake();

        $service = app(MemoryService::class);

        try {
            $service->remember($this->user, 'O CPF do usuário é 123.456.789-01', 'fact', MemoryStatus::HYPOTHESIS);

            $this->fail('Expected SensitiveMemoryRejectedException was not thrown.');
        } catch (SensitiveMemoryRejectedException) {
            $this->assertSame(0, Memory::query()->count());
        }

        $explicit = $service->remember(
            $this->user,
            'A senha do cofre é 1234',
            'fact',
            MemoryStatus::CONFIRMED,
            source: Message::factory()
                ->for(Conversation::factory()->for($this->user))
                ->create(),
            explicit: true,
        );

        $this->assertTrue($explicit->isConfirmed());
        $this->assertSame(1, Memory::query()->count());
    }
}
