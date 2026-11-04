<?php

namespace App\Http\Controllers\Api\V1\Voice;

use App\Exceptions\RealtimeProviderException;
use App\Exceptions\RealtimeUnavailableException;
use App\Exceptions\UsageLimitExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Voice\StoreVoiceSessionRequest;
use App\Http\Resources\VoiceSessionResource;
use App\Models\User;
use App\Models\VoiceSession;
use App\Services\VoiceSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * CT-01: issues the ephemeral Realtime credential for a new wake session or
 * renews it for an existing one. Cost limit → 402, foreign session → 403,
 * Realtime unavailable → 409, provider failure → 503.
 */
class VoiceSessionController extends Controller
{
    public function __construct(private readonly VoiceSessionService $sessions) {}

    public function store(StoreVoiceSessionRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $session = null;

        if ($request->filled('voice_session_id')) {
            $session = VoiceSession::query()->findOrFail($request->integer('voice_session_id'));

            Gate::authorize('update', $session);
        }

        try {
            [$session, $secret] = $session === null
                ? $this->sessions->open($user)
                : $this->sessions->renew($user, $session);
        } catch (UsageLimitExceededException) {
            abort(402, __('assistant.errors.usage_limit_exceeded'));
        } catch (RealtimeUnavailableException $exception) {
            abort(409, $exception->getMessage());
        } catch (RealtimeProviderException $exception) {
            abort(503, $exception->getMessage());
        }

        return (new VoiceSessionResource($session, $secret))
            ->response()
            ->setStatusCode(201);
    }
}
