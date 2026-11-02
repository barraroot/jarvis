<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an embedding provider returns a vector whose dimension does not
 * match the `memory_embeddings.embedding vector(1536)` column (Phase 10).
 */
class EmbeddingDimensionMismatchException extends RuntimeException
{
    public function __construct(int $actual, int $expected)
    {
        parent::__construct("Embedding dimension mismatch: provider returned {$actual} dimensions, expected {$expected}.");
    }
}
