<?php

namespace App\Listeners;

use App\Events\Approval\ApprovalDecided;
use App\Events\Conversation\ConversationMessageCreated;
use App\Models\Approval;
use App\Models\ExecutionStatus;
use App\Models\Message;
use App\Models\MessageRole;

/**
 * On denial (or expiry) the agent is told the tool will not run: a completed
 * assistant message lands in the conversation, so the resumed run sees the
 * outcome as part of its history (US-8.3, workflow 4 step 4).
 */
class PublishDeniedApproval
{
    public function handle(ApprovalDecided $event): void
    {
        if ($event->approved) {
            return;
        }

        $approval = $event->approval->fresh();

        if ($approval === null) {
            return;
        }

        $conversationId = $approval->task?->conversation_id ?? $approval->agentExecution?->conversation_id;

        if ($conversationId === null) {
            return;
        }

        $message = Message::query()->create([
            'conversation_id' => $conversationId,
            'user_id' => $approval->user_id,
            'message_role_id' => MessageRole::idFor(MessageRole::ASSISTANT),
            'message_source_id' => null,
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::COMPLETED),
            'content' => $this->content($approval, $event->reason),
            'payload' => [
                'type' => 'approval_denied',
                'approval_id' => $approval->id,
                'tool' => $approval->tool->identifier,
                'tool_name' => $approval->tool->name,
                'decision' => $approval->status->slug,
                'reason' => $event->reason,
            ],
        ]);

        ConversationMessageCreated::dispatch($message);
    }

    private function content(Approval $approval, ?string $reason): string
    {
        if ($approval->isExpired()) {
            return "A aprovação para executar \"{$approval->tool->name}\" expirou sem decisão.";
        }

        $content = "A execução de \"{$approval->tool->name}\" foi negada.";

        if (filled($reason)) {
            $content .= " Motivo: {$reason}";
        }

        return $content;
    }
}
