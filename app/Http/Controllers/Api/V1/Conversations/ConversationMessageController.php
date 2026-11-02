<?php

namespace App\Http\Controllers\Api\V1\Conversations;

use App\Events\Conversation\ConversationMessageCreated;
use App\Exceptions\UsageLimitExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Conversations\StoreMessageRequest;
use App\Jobs\ProcessUserMessage;
use App\Models\Conversation;
use App\Models\ExecutionStatus;
use App\Models\Message;
use App\Models\MessageRole;
use App\Models\MessageSource;
use App\Models\User;
use App\Services\CostLimitGuard;
use Illuminate\Http\JsonResponse;

/**
 * Accepts a user message, persists it and hands it to the queue (workflow 2,
 * steps 1–2): the reply is streamed asynchronously by {@see ProcessUserMessage}.
 */
class ConversationMessageController extends Controller
{
    public function store(StoreMessageRequest $request, Conversation $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->assertCostLimitAllows($user);

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'message_role_id' => MessageRole::idFor(MessageRole::USER),
            'message_source_id' => MessageSource::idFor($request->string('source')->toString()),
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::COMPLETED),
            'content' => $request->string('content')->toString(),
        ]);

        $conversation->touchActivity();

        event(new ConversationMessageCreated($message));

        ProcessUserMessage::dispatch($message)->onConnection('redis');

        return response()->json([
            'data' => [
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
            ],
        ], 202);
    }

    /**
     * Users over their monthly cost limit cannot start new AI calls (US-5.3).
     */
    private function assertCostLimitAllows(User $user): void
    {
        try {
            app(CostLimitGuard::class)->assertWithinLimit($user);
        } catch (UsageLimitExceededException) {
            abort(402, __('assistant.errors.usage_limit_exceeded'));
        }
    }
}
