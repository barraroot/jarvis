<?php

namespace App\Services;

use App\Exceptions\SensitiveMemoryRejectedException;
use App\Models\AuditAction;
use App\Models\Memory;
use App\Models\MemoryCategory;
use App\Models\MemoryEmbedding;
use App\Models\MemoryStatus;
use App\Models\Message;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Pgvector\Laravel\Distance;
use Pgvector\Laravel\Vector;

/**
 * Long-term memory lifecycle (Phase 10 / workflow 6): explicit remembering
 * with sensitive-data protection and similarity dedupe, regeneration of
 * embeddings on edit, hard delete with audit, hypothesis confirmation.
 */
class MemoryService
{
    public function __construct(
        private readonly EmbeddingService $embeddings,
        private readonly SensitiveDataDetector $sensitiveDataDetector,
        private readonly Settings $settings,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Store a memory. When the user already has a memory with cosine similarity
     * above `memory.duplicate_threshold`, the existing one is updated (content
     * and origin) instead of duplicated, keeping the strongest status. Sensitive
     * content is only accepted when the user asked explicitly (US-6.1/6.2).
     *
     * @throws SensitiveMemoryRejectedException
     */
    public function remember(
        User $user,
        string $content,
        string $categorySlug,
        string $statusSlug,
        ?Message $source = null,
        bool $explicit = false,
    ): Memory {
        if ($this->sensitiveDataDetector->containsSensitive($content) && ! $explicit) {
            throw new SensitiveMemoryRejectedException;
        }

        $vector = $this->embeddings->embed($content, $user);
        $categoryId = MemoryCategory::idFor($categorySlug);
        $statusId = MemoryStatus::idFor($statusSlug);

        $duplicate = $this->findDuplicate($user, $vector);

        if ($duplicate !== null) {
            $statusId = $this->strongestStatusId($duplicate->memory_status_id, $statusId);

            $duplicate->update([
                'content' => $content,
                'memory_category_id' => $categoryId,
                'memory_status_id' => $statusId,
                'source_conversation_id' => $source?->conversation_id ?? $duplicate->source_conversation_id,
                'source_message_id' => $source?->getKey() ?? $duplicate->source_message_id,
                'confirmed_at' => $statusId === MemoryStatus::idFor(MemoryStatus::CONFIRMED) ? ($duplicate->confirmed_at ?? now()) : null,
            ]);

            $duplicate->embedding->update([
                'embedding' => new Vector($vector),
                'ai_model_id' => $this->currentEmbeddingModelId(),
            ]);

            return $duplicate->fresh();
        }

        $memory = Memory::query()->create([
            'user_id' => $user->getKey(),
            'memory_status_id' => $statusId,
            'memory_category_id' => $categoryId,
            'content' => $content,
            'source_conversation_id' => $source?->conversation_id,
            'source_message_id' => $source?->getKey(),
            'confirmed_at' => $statusId === MemoryStatus::idFor(MemoryStatus::CONFIRMED) ? now() : null,
        ]);

        $memory->embedding()->create([
            'ai_model_id' => $this->currentEmbeddingModelId(),
            'embedding' => new Vector($vector),
        ]);

        return $memory;
    }

    /**
     * Edit the content of a memory and regenerate its embedding (US-6.4).
     */
    public function update(Memory $memory, string $content): Memory
    {
        $vector = $this->embeddings->embed($content, $memory->user);

        $memory->update(['content' => $content]);

        $memory->embedding->update([
            'embedding' => new Vector($vector),
            'ai_model_id' => $this->currentEmbeddingModelId(),
        ]);

        return $memory->fresh();
    }

    /**
     * Permanently delete the memory and its embedding, recording the audit trail
     * (US-6.4, US-12.3).
     */
    public function delete(Memory $memory, ?User $actor = null): void
    {
        DB::transaction(function () use ($memory, $actor): void {
            $memory->embedding()->delete();
            $memory->delete();

            $this->auditLogger->log(AuditAction::MEMORY_DELETED, $actor, $memory, [
                'user_id' => $memory->user_id,
                'status' => $memory->status->slug,
                'category' => $memory->category->slug,
            ]);
        });
    }

    /**
     * Promote a hypothesis to a confirmed memory (US-6.2).
     */
    public function confirm(Memory $memory): Memory
    {
        $memory->update([
            'memory_status_id' => MemoryStatus::idFor(MemoryStatus::CONFIRMED),
            'confirmed_at' => $memory->confirmed_at ?? now(),
        ]);

        return $memory->fresh();
    }

    /**
     * Reject a hypothesis: same as a hard delete.
     */
    public function discard(Memory $memory, ?User $actor = null): void
    {
        $this->delete($memory, $actor);
    }

    /**
     * The user's most similar memory above the duplicate threshold, if any.
     */
    private function findDuplicate(User $user, array $vector): ?Memory
    {
        $row = MemoryEmbedding::query()
            ->join('memories', 'memories.id', '=', 'memory_embeddings.memory_id')
            ->where('memories.user_id', $user->getKey())
            ->nearestNeighbors('embedding', $vector, Distance::Cosine)
            ->whereRaw('memory_embeddings.embedding <=> ? <= ?', [new Vector($vector), 1 - $this->settings->memoryDuplicateThreshold()])
            ->addSelect('memories.id as memories_row_id')
            ->first();

        if ($row === null) {
            return null;
        }

        return Memory::query()->with('embedding')->find((int) $row->getAttribute('memories_row_id'));
    }

    /**
     * `confirmed` is stronger than `hypothesis`; ties keep the existing status.
     */
    private function strongestStatusId(int $existingStatusId, int $newStatusId): int
    {
        $confirmedId = MemoryStatus::idFor(MemoryStatus::CONFIRMED);

        if ($existingStatusId === $confirmedId || $newStatusId === $confirmedId) {
            return $confirmedId;
        }

        return $existingStatusId;
    }

    private function currentEmbeddingModelId(): int
    {
        return app(ModelSelector::class)->embeddingModel()->getKey();
    }
}
