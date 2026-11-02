<?php

namespace App\Http\Resources;

use App\Models\Memory;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of a conversation message. Internal routing metadata
 * (`meta`) is never exposed — except `meta.hypotheses`, surfaced as the
 * minimal payload the UI needs to render the confirmation prompt (US-6.2).
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
            'hypotheses' => $this->hypothesesPayload(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * The hypothesis memories proposed after this reply: id, content and
     * current status, so the card can confirm/discard each one.
     *
     * @return list<array{id: int, content: string, status: string}>|null
     */
    private function hypothesesPayload(): ?array
    {
        $ids = $this->meta['hypotheses'] ?? null;

        if (! is_array($ids) || $ids === []) {
            return null;
        }

        return Memory::query()
            ->with('status')
            ->whereIn('id', $ids)
            ->get()
            ->map(fn (Memory $memory): array => [
                'id' => $memory->id,
                'content' => $memory->content,
                'status' => $memory->status->slug,
            ])
            ->all();
    }
}
