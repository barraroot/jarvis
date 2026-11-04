<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use App\Models\Visualization;
use App\Models\VoiceSession;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Background tasks requested by voice (CT-02, RF-11, RF-19).
 *
 * The voice session always arrives already resolved and owned by the caller —
 * this service never infers "the most recent" session. An inactive session
 * (D11) is rejected before anything is written; otherwise the agent comes from
 * the `IntentRouter` rules, the model from `ModelSelector`, and the task is
 * created and the session activity bumped in the same transaction.
 */
class VoiceTaskService
{
    public function __construct(
        private readonly IntentRouter $router,
        private readonly ModelSelector $modelSelector,
        private readonly TaskService $tasks,
        private readonly Settings $settings,
    ) {}

    /**
     * @throws ValidationException
     */
    public function start(User $user, VoiceSession $session, string $request, ?Visualization $visualization): Task
    {
        $this->assertActive($session);

        $conversation = $session->conversation()->withTrashed()->firstOrFail();

        $agent = $this->router->selectAgent($request);
        $model = $this->modelSelector->forConversation($conversation, $agent);

        return DB::transaction(function () use ($user, $session, $agent, $request, $visualization, $model): Task {
            $task = $this->tasks->createFromVoice($user, $session, $agent, $request, $visualization, $model);

            $session->touchActivity();

            return $task;
        });
    }

    /**
     * Inactive voice session (idle beyond the TTL or token ceiling reached,
     * D11) → 422 with a pt-BR message.
     *
     * @throws ValidationException
     */
    public function assertActive(VoiceSession $session): void
    {
        if (! $session->isActive($this->settings)) {
            throw ValidationException::withMessages([
                'voice_session_id' => __('voice.session_inactive'),
            ]);
        }
    }
}
