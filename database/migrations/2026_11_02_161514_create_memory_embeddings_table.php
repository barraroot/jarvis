<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('memory_embeddings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('memory_id')->unique()->constrained('memories')->cascadeOnDelete();
            $table->foreignId('ai_model_id')->constrained('ai_models')->restrictOnDelete();
            $table->vector('embedding', 1536);
            $table->timestamps();
        });

        DB::statement('CREATE INDEX memory_embeddings_embedding_hnsw ON memory_embeddings USING hnsw (embedding vector_cosine_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('memory_embeddings');
    }
};
