<?php

namespace Tests\Feature;

use App\Ai\Agents\RuntimeAgent;
use App\Jobs\ProcessUserMessage;
use App\Models\Agent;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Conversation;
use App\Models\ExecutionStatus;
use App\Models\Message;
use App\Models\MessageRole;
use App\Models\ResultType;
use App\Models\Task;
use App\Models\User;
use App\Services\AgentRunner;
use App\Services\RunOptions;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Ai;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\StructuredAnonymousAgent;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class StructuredResultPersistenceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    private AiModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->user = User::factory()->create();

        $provider = AiProvider::factory()->create();
        $this->model = AiModel::factory()->defaultText()->for($provider, 'provider')->create();

        AiModel::factory()->defaultEmbedding()->for($provider, 'provider')->create();
        Embeddings::fake();
    }

    public function test_table_payload_is_persisted_with_result_type(): void
    {
        Queue::fake();
        Event::fake();

        $conversation = Conversation::factory()->create(['user_id' => $this->user->id, 'ai_model_id' => $this->model->id]);
        $userMessage = Message::factory()->for($conversation, 'conversation')->create(['content' => 'Liste os clientes em atraso']);

        Ai::fakeAgent(AnonymousAgent::class, function (string $prompt, mixed $attachments, mixed $provider, string $model): TextResponse {
            return new TextResponse(
                "Clientes em atraso:\n\n| Cliente | Atraso |\n|---|---|\n| ACME | 12 |\n| Beta | 3 |",
                new TextUsage(30, 20),
                new Meta($provider->name(), $model),
            );
        });

        Ai::fakeAgent(StructuredAnonymousAgent::class, function (string $prompt, mixed $attachments, mixed $provider, string $model): StructuredTextResponse {
            return new StructuredTextResponse([
                'result' => [
                    'type' => 'table',
                    'columns' => ['Cliente', 'Atraso'],
                    'rows' => [['ACME', 12], ['Beta', 3]],
                    'caption' => 'Clientes em atraso',
                ],
            ], '', new TextUsage(10, 5), new Meta($provider->name(), $model));
        });

        (new ProcessUserMessage($userMessage))->handle();

        $assistant = Message::query()->where('message_role_id', MessageRole::idFor(MessageRole::ASSISTANT))->firstOrFail();

        $this->assertSame(ResultType::idFor(ResultType::TABLE), $assistant->result_type_id);
        $this->assertSame('table', $assistant->payload['type']);
        $this->assertSame(['Cliente', 'Atraso'], $assistant->payload['columns']);
        $this->assertSame([['ACME', 12], ['Beta', 3]], $assistant->payload['rows']);
        $this->assertSame('Clientes em atraso', $assistant->payload['caption']);
        $this->assertSame(ExecutionStatus::idFor(ExecutionStatus::COMPLETED), $assistant->execution_status_id);
    }

    public function test_invalid_model_payload_is_stored_as_text_fallback(): void
    {
        Queue::fake();
        Event::fake();

        $conversation = Conversation::factory()->create(['user_id' => $this->user->id, 'ai_model_id' => $this->model->id]);
        $userMessage = Message::factory()->for($conversation, 'conversation')->create(['content' => 'Liste os clientes em atraso']);

        Ai::fakeAgent(AnonymousAgent::class, function (string $prompt, mixed $attachments, mixed $provider, string $model): TextResponse {
            return new TextResponse(
                "| Cliente | Atraso |\n|---|---|\n| ACME | 12 |",
                new TextUsage(30, 20),
                new Meta($provider->name(), $model),
            );
        });

        // The model emits a table branch missing the required "rows" key.
        Ai::fakeAgent(StructuredAnonymousAgent::class, function (string $prompt, mixed $attachments, mixed $provider, string $model): StructuredTextResponse {
            return new StructuredTextResponse([
                'result' => ['type' => 'table', 'columns' => ['Cliente']],
            ], '', new TextUsage(10, 5), new Meta($provider->name(), $model));
        });

        (new ProcessUserMessage($userMessage))->handle();

        $assistant = Message::query()->where('message_role_id', MessageRole::idFor(MessageRole::ASSISTANT))->firstOrFail();

        $this->assertSame(ResultType::idFor(ResultType::TEXT), $assistant->result_type_id);
        $this->assertSame('text', $assistant->payload['type']);
        $this->assertTrue($assistant->payload['meta']['result_invalid']);
    }

    public function test_speech_summary_is_present_for_table_results(): void
    {
        Queue::fake();
        Event::fake();

        $conversation = Conversation::factory()->create(['user_id' => $this->user->id, 'ai_model_id' => $this->model->id]);
        $userMessage = Message::factory()->for($conversation, 'conversation')->create(['content' => 'Liste os clientes em atraso']);

        Ai::fakeAgent(AnonymousAgent::class, function (string $prompt, mixed $attachments, mixed $provider, string $model): TextResponse {
            return new TextResponse(
                "| Cliente | Atraso |\n|---|---|\n| ACME | 12 |",
                new TextUsage(30, 20),
                new Meta($provider->name(), $model),
            );
        });

        Ai::fakeAgent(StructuredAnonymousAgent::class, function (string $prompt, mixed $attachments, mixed $provider, string $model): StructuredTextResponse {
            return new StructuredTextResponse([
                'result' => [
                    'type' => 'table',
                    'columns' => ['Cliente'],
                    'rows' => [['ACME', 12]],
                    'speech' => 'Um cliente em atraso: ACME.',
                    'suggested_actions' => [
                        ['label' => 'Enviar lembrete', 'request' => 'Envie um lembrete de pagamento à ACME'],
                    ],
                ],
            ], '', new TextUsage(10, 5), new Meta($provider->name(), $model));
        });

        (new ProcessUserMessage($userMessage))->handle();

        $assistant = Message::query()->where('message_role_id', MessageRole::idFor(MessageRole::ASSISTANT))->firstOrFail();

        $this->assertSame('Um cliente em atraso: ACME.', $assistant->payload['speech']);
        $this->assertSame([['label' => 'Enviar lembrete', 'request' => 'Envie um lembrete de pagamento à ACME']], $assistant->payload['suggested_actions']);
        $this->assertSame($assistant->payload['suggested_actions'], $assistant->suggested_actions);
    }

    public function test_agent_run_persists_structured_result_into_task(): void
    {
        Event::fake();

        $agent = Agent::factory()->create();
        $task = Task::factory()->for($this->user)->for($agent)->create();

        Ai::fakeAgent(RuntimeAgent::class, function (string $prompt, mixed $attachments, mixed $provider, string $model): TextResponse {
            return new TextResponse(
                "Resultado:\n\n- Cliente ACME\n- Cliente Beta\n- Cliente Gamma",
                new TextUsage(30, 20),
                new Meta($provider->name(), $model),
            );
        });

        Ai::fakeAgent(StructuredAnonymousAgent::class, function (string $prompt, mixed $attachments, mixed $provider, string $model): StructuredTextResponse {
            return new StructuredTextResponse([
                'result' => [
                    'type' => 'list',
                    'title' => 'Clientes',
                    'items' => [
                        ['label' => 'ACME'],
                        ['label' => 'Beta'],
                        ['label' => 'Gamma'],
                    ],
                ],
            ], '', new TextUsage(10, 5), new Meta($provider->name(), $model));
        });

        $execution = app(AgentRunner::class)->run($agent, $this->user, 'Liste os clientes', new RunOptions(task: $task));

        $this->assertSame(ExecutionStatus::idFor(ExecutionStatus::COMPLETED), $execution->execution_status_id);
        $this->assertSame('list', $execution->meta['result']['type']);

        $task = app(TaskService::class)->markCompleted($task, $execution);

        $this->assertSame(ResultType::idFor(ResultType::LIST), $task->result_type_id);
        $this->assertSame('list', $task->result['type']);
        $this->assertSame(['label' => 'ACME'], $task->result['items'][0]);
        $this->assertNotNull($task->completed_at);
    }
}
