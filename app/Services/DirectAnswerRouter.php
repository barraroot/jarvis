<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;

/**
 * Phase 7 routing: every message is answered directly, with the model chosen
 * for the conversation (or the global default) and no extra instructions
 * (US-3.3 direct-answer path).
 */
class DirectAnswerRouter implements MessageRouter
{
    public function __construct(private readonly ModelSelector $modelSelector) {}

    public function route(Message $message): RoutedMessage
    {
        $conversation = $message->conversation()->withTrashed()->firstOrFail();

        return new RoutedMessage(
            $this->modelSelector->forConversation($conversation),
        );
    }
}
