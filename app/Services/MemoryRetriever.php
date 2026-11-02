<?php

namespace App\Services;

use App\Models\Memory;
use App\Models\MemoryEmbedding;
use App\Models\MemoryStatus;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Pgvector\Laravel\Distance;
use Pgvector\Laravel\Vector;

/**
 * Semantic retrieval of the user's confirmed memories (US-6.3): the query is
 * embedded, `memory_embeddings` is searched by cosine distance with a join on
 * `memories` filtering owner and status, and the top-N results above the
 * similarity threshold are returned (never hypotheses, never other users).
 */
class MemoryRetriever
{
    public function __construct(
        private readonly EmbeddingService $embeddings,
        private readonly Settings $settings,
    ) {}

    /**
     * @return Collection<int, Memory> Most similar confirmed memories, ordered by similarity (desc).
     */
    public function relevantFor(User $user, string $query): Collection
    {
        $vector = $this->embeddings->embed($query, $user);

        $rows = MemoryEmbedding::query()
            ->join('memories', 'memories.id', '=', 'memory_embeddings.memory_id')
            ->where('memories.user_id', $user->getKey())
            ->where('memories.memory_status_id', MemoryStatus::idFor(MemoryStatus::CONFIRMED))
            ->nearestNeighbors('embedding', $vector, Distance::Cosine)
            ->whereRaw('memory_embeddings.embedding <=> ? <= ?', [new Vector($vector), $this->maxDistance()])
            ->addSelect('memories.id as memories_row_id')
            ->limit(max(1, $this->settings->memoryTopN()))
            ->get();

        $orderedIds = $rows->map(fn (MemoryEmbedding $row): int => (int) $row->getAttribute('memories_row_id'))->all();

        if ($orderedIds === []) {
            return new Collection;
        }

        $now = now();

        Memory::query()->whereIn('id', $orderedIds)->update(['last_used_at' => $now, 'updated_at' => $now]);

        $memories = Memory::query()->whereIn('id', $orderedIds)->get()->keyBy('id');

        return collect($orderedIds)
            ->map(fn (int $id): ?Memory => $memories->get($id))
            ->filter()
            ->each(fn (Memory $memory) => $memory->setAttribute('last_used_at', $now))
            ->values();
    }

    /**
     * Cosine distance at the similarity threshold: similarity = 1 - distance.
     */
    private function maxDistance(): float
    {
        return 1 - $this->settings->memorySimilarityThreshold();
    }
}
