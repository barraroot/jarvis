<?php

namespace App\Http\Resources;

use App\Models\Memory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of a user memory (US-6.4): content, status, category,
 * origin and dates — never the embedding itself.
 *
 * @mixin Memory
 */
class MemoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'content' => $this->content,
            'status' => $this->status->slug,
            'category' => $this->category->slug,
            'source_conversation_id' => $this->source_conversation_id,
            'source_message_id' => $this->source_message_id,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
