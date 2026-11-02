<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of a conversation message. Internal routing metadata
 * (`meta`) is never exposed.
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'role' => $this->role->slug,
            'source' => $this->source?->slug,
            'status' => $this->status->slug,
            'content' => $this->content,
            'payload' => $this->payload,
            'ai_model_id' => $this->ai_model_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
