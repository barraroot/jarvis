<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of a conversation owned by the requesting operator.
 *
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'ai_model_id' => $this->ai_model_id,
            'model' => AiModelResource::make($this->whenLoaded('model')),
        ];
    }
}
