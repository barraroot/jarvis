<?php

namespace App\Services;

use App\Events\Approval\ApprovalDecided;
use App\Events\Assistant\AssistantStateChanged;
use App\Events\Conversation\ConversationMessageCreated;
use App\Events\NotificationCreated;
use App\Exceptions\ApprovalNotDecidableException;
use App\Models\Approval;
use App\Models\ApprovalStatus;
use App\Models\AuditAction;
use App\Models\ExecutionStatus;
use App\Models\Message;
use App\Models\MessageRole;
use App\Models\Notification;
use App\Models\NotificationType;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Tool;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Settings;
use App\Tools\ToolContext;

/**
 * Approval lifecycle (US-8.3): gating write/sensitive tools behind an explicit
 * owner decision, with audit trail, task state and operator notification.
 */
class ApprovalService
{
    public function __construct(
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Open an approval for a tool call: the tool must NOT run yet. Puts the
     * task into `Waiting approval`, flips the core to EXECUTING and notifies
     * the owner.
     *
     * @param  array<string, mixed>  $input
     */
    public function request(Tool $tool, array $input, ToolContext $context): Approval
    {
        $approval = Approval::query()->create([
            'user_id' => $context->user->id,
            'tool_id' => $tool->id,
            'approval_status_id' => ApprovalStatus::idFor(ApprovalStatus::PENDING),
            'task_id' => $context->task?->id,
            'agent_execution_id' => $context->agentExecution?->id,
            'input' => $input,
            'requested_at' => now(),
            'expires_at' => now()->addHours($this->settings->approvalTtlHours()),
        ]);

        $this->pauseTask($context->task);

        AssistantStateChanged::dispatch(
            $context->user,
            AssistantStateChanged::EXECUTING,
            $context->task?->conversation_id,
        );

        $notification = Notification::query()->create([
            'user_id' => $context->user->id,
            'notification_type_id' => NotificationType::idFor(NotificationType::APPROVAL_PENDING),
            'title' => 'Aprovação pendente',
            'body' => "A ferramenta \"{$tool->name}\" aguarda sua aprovação.",
            'data' => ['approval_id' => $approval->id],
        ]);

        NotificationCreated::dispatch($notification);

        $this->publishApprovalRequest($approval, $tool, $context);

        return $approval;
    }

    /**
     * Approve: only the owner, only while pending, only before expiry. On
     * success the tool executes exactly once via `ExecuteApprovedTool`.
     */
    public function approve(Approval $approval, User $decider): Approval
    {
        $this->assertDecidable($approval, $decider);

        $this->markApproved($approval, $decider);

        $this->resumeTask($approval->task);

        ApprovalDecided::dispatch($approval, $decider, approved: true);

        return $approval;
    }

    /**
     * Deny: the tool never runs; the task/agent resumes knowing the denial.
     */
    public function deny(Approval $approval, User $decider, ?string $reason = null): Approval
    {
        $this->assertDecidable($approval, $decider);

        $approval->update([
            'approval_status_id' => ApprovalStatus::idFor(ApprovalStatus::DENIED),
            'decided_at' => now(),
            'decided_by_user_id' => $decider->id,
            'decision_reason' => $reason,
        ]);

        $this->audit->log(AuditAction::APPROVAL_DENIED, $decider, $approval, [
            'tool' => $approval->tool->identifier,
            'reason' => $reason,
        ]);

        $this->resumeTask($approval->task);

        ApprovalDecided::dispatch($approval, $decider, approved: false, reason: $reason);

        return $approval;
    }

    /**
     * Mark an approval expired (scheduler): treated as a denial, so the task
     * resumes and the agent is told the tool will not run.
     */
    public function expire(Approval $approval): Approval
    {
        $approval->update([
            'approval_status_id' => ApprovalStatus::idFor(ApprovalStatus::EXPIRED),
            'decided_at' => now(),
        ]);

        $this->audit->log(AuditAction::APPROVAL_EXPIRED, null, $approval, [
            'tool' => $approval->tool->identifier,
        ]);

        $this->resumeTask($approval->task);

        ApprovalDecided::dispatch($approval, null, approved: false);

        return $approval;
    }

    /**
     * Record the approval granted by the SDK agent path without re-dispatching
     * the decision event (the SDK already made the decision and is about to
     * call the tool). Idempotent for already-approved rows.
     */
    public function markApproved(Approval $approval, User $decider): Approval
    {
        if ($approval->isPending()) {
            $approval->update([
                'approval_status_id' => ApprovalStatus::idFor(ApprovalStatus::APPROVED),
                'decided_at' => now(),
                'decided_by_user_id' => $decider->id,
            ]);

            $this->audit->log(AuditAction::APPROVAL_APPROVED, $decider, $approval, [
                'tool' => $approval->tool->identifier,
            ]);
        }

        return $approval;
    }

    /**
     * Only the owner may decide, only pending approvals are decidable and an
     * expired window counts as a denial (US-8.3).
     *
     * @throws ApprovalNotDecidableException
     */
    private function assertDecidable(Approval $approval, User $decider): void
    {
        if ($approval->user_id !== $decider->id) {
            throw new ApprovalNotDecidableException(
                ApprovalNotDecidableException::NOT_OWNER,
                'Only the approval owner may decide it.',
            );
        }

        if (! $approval->isPending()) {
            throw new ApprovalNotDecidableException(
                ApprovalNotDecidableException::NOT_PENDING,
                'The approval was already decided.',
            );
        }

        if ($approval->isExpired()) {
            throw new ApprovalNotDecidableException(
                ApprovalNotDecidableException::EXPIRED,
                'The approval window has expired.',
            );
        }
    }

    /**
     * A gated tool pauses its task until the owner decides.
     */
    private function pauseTask(?Task $task): void
    {
        if ($task !== null && $task->hasStatus(TaskStatus::RUNNING, TaskStatus::QUEUED, TaskStatus::PENDING)) {
            $task->update(['task_status_id' => TaskStatus::idFor(TaskStatus::WAITING_APPROVAL)]);
        }
    }

    /**
     * Surfaces the request in the conversation it came from: an assistant
     * message whose `payload.type = approval_request` drives the ApprovalCard
     * in the chat (US-8.3).
     */
    private function publishApprovalRequest(Approval $approval, Tool $tool, ToolContext $context): void
    {
        $conversationId = $context->task?->conversation_id;

        if ($conversationId === null) {
            return;
        }

        $message = Message::query()->create([
            'conversation_id' => $conversationId,
            'user_id' => $context->user->id,
            'message_role_id' => MessageRole::idFor(MessageRole::ASSISTANT),
            'message_source_id' => null,
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::COMPLETED),
            'content' => "Solicita aprovação para executar \"{$tool->name}\".",
            'payload' => [
                'type' => 'approval_request',
                'approval_id' => $approval->id,
                'tool' => $tool->identifier,
                'tool_name' => $tool->name,
                'effect' => [
                    'is_write' => $tool->is_write,
                    'is_sensitive' => $tool->is_sensitive,
                ],
                'params' => $approval->input,
            ],
        ]);

        ConversationMessageCreated::dispatch($message);
    }

    /**
     * After a decision the task leaves the approval hold: the flow resumes
     * (on denial the agent is told the action was refused).
     */
    private function resumeTask(?Task $task): void
    {
        if ($task !== null && $task->hasStatus(TaskStatus::WAITING_APPROVAL)) {
            $task->update(['task_status_id' => TaskStatus::idFor(TaskStatus::RUNNING)]);
        }
    }
}
