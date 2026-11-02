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
        Schema::create('ai_models', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->string('name');
            $table->string('model_identifier');
            $table->integer('context_window')->nullable();
            $table->decimal('input_cost_per_million', 10, 4)->default(0);
            $table->decimal('output_cost_per_million', 10, 4)->default(0);
            $table->boolean('is_default_text')->default(false);
            $table->boolean('is_default_embedding')->default(false);
            $table->integer('fallback_order')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['ai_provider_id', 'model_identifier']);
        });

        DB::statement('CREATE UNIQUE INDEX ai_models_default_text_unique ON ai_models (is_default_text) WHERE is_default_text');
        DB::statement('CREATE UNIQUE INDEX ai_models_default_embedding_unique ON ai_models (is_default_embedding) WHERE is_default_embedding');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};
