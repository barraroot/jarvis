<?php

namespace App\Models;

use Database\Factories\AiModelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'ai_provider_id',
    'name',
    'model_identifier',
    'context_window',
    'input_cost_per_million',
    'output_cost_per_million',
    'is_default_text',
    'is_default_embedding',
    'is_default_realtime',
    'fallback_order',
    'is_active',
])]
class AiModel extends Model
{
    /** @use HasFactory<AiModelFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context_window' => 'integer',
            'input_cost_per_million' => 'decimal:4',
            'output_cost_per_million' => 'decimal:4',
            'is_default_text' => 'boolean',
            'is_default_embedding' => 'boolean',
            'is_default_realtime' => 'boolean',
            'fallback_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Estimated cost in USD for a number of input and output tokens, using per-million rates.
     */
    public function costFor(int $inputTokens, int $outputTokens): float
    {
        $inputCost = $inputTokens * (float) $this->input_cost_per_million / 1_000_000;
        $outputCost = $outputTokens * (float) $this->output_cost_per_million / 1_000_000;

        return round($inputCost + $outputCost, 6);
    }

    public function hasCapability(string $capabilitySlug): bool
    {
        return $this->capabilities()->where('slug', $capabilitySlug)->exists();
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Models capable of text generation.
     */
    #[Scope]
    protected function text(Builder $query): void
    {
        $query->whereHas('capabilities', fn (Builder $capabilities) => $capabilities->where('slug', AiModelCapability::TEXT));
    }

    /**
     * Models capable of producing embeddings.
     */
    #[Scope]
    protected function embeddings(Builder $query): void
    {
        $query->whereHas('capabilities', fn (Builder $capabilities) => $capabilities->where('slug', AiModelCapability::EMBEDDINGS));
    }

    /**
     * Models capable of OpenAI Realtime voice sessions (RF-09a).
     */
    #[Scope]
    protected function realtime(Builder $query): void
    {
        $query->whereHas('capabilities', fn (Builder $capabilities) => $capabilities->where('slug', AiModelCapability::REALTIME));
    }

    /**
     * Models that take part in the fallback chain, ordered by priority.
     */
    #[Scope]
    protected function inFallbackOrder(Builder $query): void
    {
        $query->whereNotNull('fallback_order')->orderBy('fallback_order');
    }

    /** @return BelongsTo<AiProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    /** @return BelongsToMany<AiModelCapability, $this> */
    public function capabilities(): BelongsToMany
    {
        return $this->belongsToMany(AiModelCapability::class, 'ai_model_capability', 'ai_model_id', 'ai_model_capability_id');
    }

    /** @return HasMany<Agent, $this> */
    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class, 'ai_model_id');
    }

    /** @return HasMany<Conversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'ai_model_id');
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'ai_model_id');
    }

    /** @return HasMany<UsageRecord, $this> */
    public function usageRecords(): HasMany
    {
        return $this->hasMany(UsageRecord::class, 'ai_model_id');
    }

    /** @return HasMany<VoiceSession, $this> */
    public function voiceSessions(): HasMany
    {
        return $this->hasMany(VoiceSession::class, 'ai_model_id');
    }

    /** @return HasMany<MemoryEmbedding, $this> */
    public function memoryEmbeddings(): HasMany
    {
        return $this->hasMany(MemoryEmbedding::class, 'ai_model_id');
    }
}
