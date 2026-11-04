<?php

namespace App\Http\Controllers\Api\V1\Voice;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Voice\StoreVoiceTurnsRequest;
use App\Http\Resources\MessageResource;
use App\Models\VoiceSession;
use App\Services\VoiceTurnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * CT-10: stores a batch of transcribed voice turns as `messages` of the voice
 * session's conversation. Foreign session → 403.
 */
class VoiceTurnController extends Controller
{
    public function __construct(private readonly VoiceTurnService $turns) {}

    public function store(StoreVoiceTurnsRequest $request, VoiceSession $voiceSession): JsonResponse
    {
        Gate::authorize('update', $voiceSession);

        /** @var list<array{role: string, content: string, spoken_at: string}> $turns */
        $turns = $request->validated('turns');

        $messages = $this->turns->store($voiceSession, $turns);

        return MessageResource::collection($messages)
            ->response()
            ->setStatusCode(201);
    }
}
