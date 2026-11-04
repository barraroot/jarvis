<?php

namespace App\Services;

use App\Exceptions\RealtimeProviderException;
use App\Exceptions\RealtimeUnavailableException;
use App\Exceptions\UsageLimitExceededException;
use App\Models\Conversation;
use App\Models\User;
use App\Models\VoiceSession;
use Illuminate\Support\Facades\DB;

/**
 * Opens and renews Realtime voice sessions (CT-01).
 *
 * Every call checks the monthly cost limit (RF-10). A new wake session gets a
 * brand-new conversation (RF-21); a renewal reuses the session's conversation.
 * Both bump `last_issued_at`/`last_activity_at` (D11).
 */
class VoiceSessionService
{
    public function __construct(
        private readonly CostLimitGuard $costLimit,
        private readonly RealtimeSessionService $realtime,
    ) {}

    /**
     * @return array{0: VoiceSession, 1: RealtimeClientSecret}
     *
     * @throws UsageLimitExceededException
     * @throws RealtimeUnavailableException
     * @throws RealtimeProviderException
     */
    public function open(User $user): array
    {
        $this->costLimit->assertWithinLimit($user);

        $model = $this->realtime->realtimeModel();

        $session = DB::transaction(function () use ($user, $model): VoiceSession {
            $conversation = $user->conversations()->create([
                'title' => Conversation::PROVISIONAL_TITLE,
                'last_activity_at' => now(),
            ]);

            return $user->voiceSessions()->create([
                'conversation_id' => $conversation->id,
                'ai_model_id' => $model->id,
                'total_tokens' => 0,
                'last_issued_at' => now(),
                'last_activity_at' => now(),
            ]);
        });

        try {
            $secret = $this->realtime->issue($user, $session, $model);
        } catch (RealtimeProviderException $exception) {
            // No credential, no wake session: drop the empty session and conversation.
            DB::transaction(function () use ($session): void {
                $conversationId = $session->conversation_id;
                $session->delete();
                Conversation::query()->whereKey($conversationId)->forceDelete();
            });

            throw $exception;
        }

        return [$session, $secret];
    }

    /**
     * @return array{0: VoiceSession, 1: RealtimeClientSecret}
     *
     * @throws UsageLimitExceededException
     * @throws RealtimeUnavailableException
     * @throws RealtimeProviderException
     */
    public function renew(User $user, VoiceSession $session): array
    {
        $this->costLimit->assertWithinLimit($user);

        $model = $this->realtime->realtimeModel();

        $secret = $this->realtime->issue($user, $session, $model);

        $session->forceFill([
            'ai_model_id' => $model->id,
            'last_issued_at' => now(),
            'last_activity_at' => now(),
        ])->save();

        return [$session, $secret];
    }
}
