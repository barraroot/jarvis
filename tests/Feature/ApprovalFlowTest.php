<?php

namespace Tests\Feature;

use App\Events\Assistant\AssistantStateChanged;
use App\Events\Conversation\ConversationMessageCreated;
use App\Events\NotificationCreated;
use App\Events\Tool\ToolCompleted;
use App\Events\Tool\ToolStarted;
use App\Exceptions\ApprovalNotDecidableException;
use App\Models\Approval;
use App\Models\ApprovalStatus;
use App\Models\AuditAction;
use App\Models\Conversation;
use App\Models\ExecutionStatus;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Tool;
use App\Models\ToolExecution;
use App\Models\User;
use App\Services\ApprovalService;
use App\Tools\Contracts\JarvisTool;
use App\Tools\ToolContext;
use App\Tools\ToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class ApprovalFlowTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->user = User::factory()->create();
    }

    public function test_write_tool_creates_pending_approval_and_does_not_execute(): void
    {
        Event::fake([AssistantStateChanged::class, NotificationCreated::class, ToolStarted::class]);

        WriteEchoInternalTool::$called = false;

        $tool = $this->writeTool();
        $task = Task::factory()->running()->for($this->user)->create();

        $result = app(ToolExecutor::class)->execute(
            $tool,
            ['message' => 'disparar ação'],
            new ToolContext($this->user, task: $task),
        );

        $this->assertTrue($result->isPendingApproval());
        $this->assertNotNull($result->approval);
        $this->assertFalse(WriteEchoInternalTool::$called, 'The handler must not run before approval.');

        $approval = $result->approval;

        $this->assertSame($this->user->id, $approval->user_id);
        $this->assertSame($task->id, $approval->task_id);
        $this->assertSame(['message' => 'disparar ação'], $approval->input);
        $this->assertTrue($approval->isPending());
        $this->assertNotNull($approval->requested_at);
        $this->assertNotNull($approval->expires_at);

        $this->assertSame(TaskStatus::WAITING_APPROVAL, $task->fresh()->status->slug);
        $this->assertSame(0, $tool->executions()->count());

        Event::assertDispatched(AssistantStateChanged::class, fn (AssistantStateChanged $event): bool => $event->state === AssistantStateChanged::EXECUTING);

        $notification = Notification::query()->firstOrFail();
        $this->assertSame($this->user->id, $notification->user_id);
        $this->assertSame('approval_pending', $notification->type->slug);
        $this->assertSame($approval->id, $notification->data['approval_id']);

        Event::assertDispatched(NotificationCreated::class);
    }

    public function test_only_owner_can_decide(): void
    {
        $approval = $this->pendingApproval();

        $intruder = User::factory()->create();

        $this->assertThrows(
            fn (): mixed => app(ApprovalService::class)->approve($approval, $intruder),
            ApprovalNotDecidableException::class,
        );

        $this->assertThrows(
            fn (): mixed => app(ApprovalService::class)->deny($approval, $intruder, 'não'),
            ApprovalNotDecidableException::class,
        );

        $this->assertTrue($approval->fresh()->isPending());
    }

    public function test_approve_executes_once_and_audits(): void
    {
        Event::fake([ToolStarted::class, ToolCompleted::class]);

        $tool = $this->writeTool();
        $approval = $this->pendingApproval($tool);

        $task = Task::factory()->waitingApproval()->for($this->user)->create();
        $approval->update(['task_id' => $task->id]);

        app(ApprovalService::class)->approve($approval, $this->user);

        $approval->refresh();

        $this->assertSame(ApprovalStatus::APPROVED, $approval->status->slug);
        $this->assertNotNull($approval->decided_at);
        $this->assertSame($this->user->id, $approval->decided_by_user_id);

        $this->assertSame(TaskStatus::RUNNING, $task->fresh()->status->slug);

        $this->assertDatabaseHas('audit_logs', [
            'audit_action_id' => AuditAction::idFor(AuditAction::APPROVAL_APPROVED),
            'user_id' => $this->user->id,
            'subject_type' => 'App\\Models\\Approval',
            'subject_id' => $approval->id,
        ]);

        $this->assertSame(1, ToolExecution::query()->where('approval_id', $approval->id)->count());

        $execution = ToolExecution::query()->where('approval_id', $approval->id)->firstOrFail();
        $this->assertSame(ExecutionStatus::COMPLETED, $execution->status->slug);
        $this->assertSame(['echoed' => 'disparar ação'], $execution->output);

        // A second decision on the same approval is rejected; nothing runs twice.
        $this->assertThrows(
            fn (): mixed => app(ApprovalService::class)->approve($approval, $this->user),
            ApprovalNotDecidableException::class,
        );

        $this->assertSame(1, ToolExecution::query()->where('approval_id', $approval->id)->count());
    }

    public function test_deny_does_not_execute_and_audits(): void
    {
        Event::fake([ToolStarted::class, ToolCompleted::class]);

        $tool = $this->writeTool();
        $approval = $this->pendingApproval($tool);

        app(ApprovalService::class)->deny($approval, $this->user, 'Não quero enviar agora');

        $approval->refresh();

        $this->assertSame(ApprovalStatus::DENIED, $approval->status->slug);
        $this->assertSame('Não quero enviar agora', $approval->decision_reason);
        $this->assertSame($this->user->id, $approval->decided_by_user_id);
        $this->assertNotNull($approval->decided_at);

        $this->assertDatabaseHas('audit_logs', [
            'audit_action_id' => AuditAction::idFor(AuditAction::APPROVAL_DENIED),
            'user_id' => $this->user->id,
            'subject_type' => 'App\\Models\\Approval',
            'subject_id' => $approval->id,
        ]);

        $this->assertSame(0, ToolExecution::query()->count());
        $this->assertSame(0, $tool->executions()->count());
    }

    public function test_request_publishes_approval_message_in_conversation(): void
    {
        Event::fake([AssistantStateChanged::class, NotificationCreated::class, ToolStarted::class, ConversationMessageCreated::class]);

        $conversation = Conversation::factory()->for($this->user)->create();
        $task = Task::factory()->running()->for($this->user)->create(['conversation_id' => $conversation->id]);

        $result = app(ToolExecutor::class)->execute(
            $this->writeTool(),
            ['message' => 'disparar ação'],
            new ToolContext($this->user, task: $task),
        );

        $this->assertTrue($result->isPendingApproval());

        $message = Message::query()->where('conversation_id', $conversation->id)->firstOrFail();

        $this->assertSame('assistant', $message->role->slug);
        $this->assertSame('approval_request', $message->payload['type']);
        $this->assertSame($result->approval->id, $message->payload['approval_id']);
        $this->assertSame('test.write-echo', $message->payload['tool']);
        $this->assertSame(['message' => 'disparar ação'], $message->payload['params']);

        Event::assertDispatched(ConversationMessageCreated::class, fn (ConversationMessageCreated $event): bool => $event->message->is($message));
    }

    public function test_deny_publishes_denial_message_in_conversation(): void
    {
        Event::fake([ConversationMessageCreated::class]);

        $conversation = Conversation::factory()->for($this->user)->create();
        $task = Task::factory()->waitingApproval()->for($this->user)->create(['conversation_id' => $conversation->id]);

        $approval = $this->pendingApproval($this->writeTool());
        $approval->update(['task_id' => $task->id]);

        app(ApprovalService::class)->deny($approval, $this->user, 'Não quero enviar agora');

        $message = Message::query()->where('conversation_id', $conversation->id)->firstOrFail();

        $this->assertSame('assistant', $message->role->slug);
        $this->assertSame('approval_denied', $message->payload['type']);
        $this->assertSame($approval->id, $message->payload['approval_id']);
        $this->assertSame('test.write-echo', $message->payload['tool']);
        $this->assertSame('denied', $message->payload['decision']);
        $this->assertSame('Não quero enviar agora', $message->payload['reason']);
        $this->assertStringContainsString('foi negada', $message->content);
        $this->assertStringContainsString('Não quero enviar agora', $message->content);

        Event::assertDispatched(ConversationMessageCreated::class, fn (ConversationMessageCreated $event): bool => $event->message->is($message));
    }

    public function test_expired_approval_cannot_be_approved(): void
    {
        $approval = $this->pendingApproval();

        $approval->update([
            'requested_at' => now()->subDay(),
            'expires_at' => now()->subHour(),
        ]);

        $this->assertThrows(
            fn (): mixed => app(ApprovalService::class)->approve($approval, $this->user),
            ApprovalNotDecidableException::class,
        );
        $this->assertTrue($approval->fresh()->isPending());
        $this->assertSame(0, ToolExecution::query()->count());
    }

    private function pendingApproval(?Tool $tool = null): Approval
    {
        return Approval::factory()->create([
            'user_id' => $this->user->id,
            'tool_id' => ($tool ?? $this->writeTool())->id,
            'input' => ['message' => 'disparar ação'],
        ]);
    }

    private function writeTool(): Tool
    {
        return Tool::factory()->write()->create([
            'identifier' => 'test.write-echo',
            'handler_class' => WriteEchoInternalTool::class,
            'input_schema' => [
                'type' => 'object',
                'properties' => ['message' => ['type' => 'string', 'minLength' => 1]],
                'required' => ['message'],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => ['echoed' => ['type' => 'string']],
                'required' => ['echoed'],
            ],
        ]);
    }
}

class WriteEchoInternalTool implements JarvisTool
{
    public static bool $called = false;

    public function identifier(): string
    {
        return 'test.write-echo';
    }

    public function name(): string
    {
        return 'Escrita de teste';
    }

    public function description(): string
    {
        return 'Ferramenta de escrita para testes de aprovação.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['message' => ['type' => 'string', 'minLength' => 1]],
            'required' => ['message'],
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['echoed' => ['type' => 'string']],
            'required' => ['echoed'],
        ];
    }

    public function handle(array $input, ToolContext $context): array
    {
        self::$called = true;

        return ['echoed' => $input['message']];
    }
}
