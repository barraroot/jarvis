<?php

namespace App\Http\Controllers\Api\V1\Voice;

use App\Exceptions\VoiceTokenCeilingExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Voice\StoreVoiceUsageRequest;
use App\Models\VoiceSession;
use App\Services\VoiceUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * CT-07: records the usage of a Realtime `response.done`. Foreign session →
 * 403; report past the per-session ceiling → 422 with nothing recorded.
 */
class VoiceUsageController extends Controller
{
    public function __construct(private readonly VoiceUsageService $usage) {}

    public function store(StoreVoiceUsageRequest $request, VoiceSession $voiceSession): JsonResponse
    {
        Gate::authorize('update', $voiceSession);

        try {
            $totals = $this->usage->report(
                $voiceSession,
                $request->string('response_id')->toString(),
                $request->integer('usage.input_tokens'),
                $request->integer('usage.output_tokens'),
            );
        } catch (VoiceTokenCeilingExceededException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => ['usage' => [$exception->getMessage()]],
            ], 422);
        }

        return response()->json(['data' => $totals], 201);
    }
}
