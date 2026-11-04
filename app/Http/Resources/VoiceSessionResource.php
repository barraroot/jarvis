<?php

namespace App\Http\Resources;

use App\Models\VoiceSession;
use App\Services\RealtimeClientSecret;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * CT-01 grant: the voice session, its conversation and the ephemeral Realtime
 * credential. Never carries the provider key (RF-09, RNF-01).
 *
 * @mixin VoiceSession
 */
class VoiceSessionResource extends JsonResource
{
    public function __construct(VoiceSession $resource, private readonly RealtimeClientSecret $secret)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'voice_session_id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'client_secret' => $this->secret->value,
            'expires_at' => $this->secret->expiresAt->toIso8601String(),
            'model' => $this->secret->model->model_identifier,
            'voice' => $this->secret->voice,
            'listening_window_seconds' => app(Settings::class)->listeningWindowSeconds(),
        ];
    }
}
