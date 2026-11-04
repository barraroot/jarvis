<?php

namespace App\Services;

use App\Exceptions\VoiceTokenCeilingExceededException;
use App\Models\ExecutionStatus;
use App\Models\UsageRecord;
use App\Models\VoiceSession;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Records the usage the SPA reports for a Realtime session (CT-07, RF-10a).
 *
 * The session row is locked so concurrent reports cannot race past the
 * per-session token ceiling. A repeated `response_id` is ignored.
 */
class VoiceUsageService
{
    public function __construct(
        private readonly UsageRecorder $recorder,
        private readonly Settings $settings,
    ) {}

    /**
     * @return array{session_total_tokens: int, session_token_ceiling: int}
     *
     * @throws VoiceTokenCeilingExceededException
     */
    public function report(VoiceSession $voiceSession, string $responseId, int $inputTokens, int $outputTokens): array
    {
        $ceiling = $this->settings->sessionTokenCeiling();

        $total = DB::transaction(function () use ($voiceSession, $responseId, $inputTokens, $outputTokens, $ceiling): int {
            /** @var VoiceSession $session */
            $session = VoiceSession::query()
                ->with(['user', 'model'])
                ->whereKey($voiceSession->id)
                ->lockForUpdate()
                ->firstOrFail();

            $alreadyRecorded = UsageRecord::query()
                ->where('voice_session_id', $session->id)
                ->where('provider_response_id', $responseId)
                ->exists();

            if ($alreadyRecorded) {
                return $session->total_tokens;
            }

            $newTotal = $session->total_tokens + $inputTokens + $outputTokens;

            if ($newTotal > $ceiling) {
                throw new VoiceTokenCeilingExceededException($session, $ceiling);
            }

            $this->recorder->record(
                $session->user,
                $session->model,
                inputTokens: $inputTokens,
                outputTokens: $outputTokens,
                latencyMs: 0,
                statusSlug: ExecutionStatus::COMPLETED,
                voiceSession: $session,
                providerResponseId: $responseId,
            );

            $session->total_tokens = $newTotal;
            $session->last_activity_at = now();
            $session->save();

            return $newTotal;
        });

        return [
            'session_total_tokens' => $total,
            'session_token_ceiling' => $ceiling,
        ];
    }
}
