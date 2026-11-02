<?php

namespace App\Models;

use Database\Factories\MemoryEmbeddingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

#[Fillable(['memory_id', 'ai_model_id', 'embedding'])]
class MemoryEmbedding extends Model
{
    /** @use HasFactory<MemoryEmbeddingFactory> */
    use HasFactory, HasNeighbors;

    public const int DIMENSIONS = 1536;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'embedding' => Vector::class,
        ];
    }

    /** @return BelongsTo<Memory, $this> */
    public function memory(): BelongsTo
    {
        return $this->belongsTo(Memory::class);
    }

    /** @return BelongsTo<AiModel, $this> */
    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }
}
