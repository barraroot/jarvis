<?php

namespace Tests\Feature;

use App\Ai\Agents\IntentClassifier;
use App\Models\Agent;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\DirectAnswerRouter;
use App\Services\IntentRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Ai;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class IntentRouterTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    private Agent $defaultAgent;

    private Agent $researchAgent;

    private AiModel $defaultModel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $provider = AiProvider::factory()->create();
        $this->defaultModel = AiModel::factory()->defaultText()->for($provider, 'provider')->create();

        $this->user = User::factory()->create();
        $this->defaultAgent = Agent::factory()->default()->create();
        $this->researchAgent = Agent::factory()->create(['slug' => 'research', 'name' => 'Research']);
    }

    public function test_routes_each_of_the_four_intents(): void
    {
        IntentClassifier::fake(function (string $prompt): array {
            return match (true) {
                str_contains($prompt, 'diretamente') => ['intent' => 'direct_answer', 'agent_slug' => null, 'confidence' => 0.95],
                str_contains($prompt, 'ferramenta') => ['intent' => 'use_tool', 'agent_slug' => null, 'confidence' => 0.8],
                str_contains($prompt, 'pesquisa') => ['intent' => 'delegate_agent', 'agent_slug' => 'research', 'confidence' => 0.9],
                default => ['intent' => 'long_task', 'agent_slug' => 'research', 'confidence' => 0.85],
            };
        });

        $router = app(IntentRouter::class);

        $direct = $router->route($this->userMessage('Responda diretamente, por favor.'));
        $this->assertNull($direct->agent);
        $this->assertSame(DirectAnswerRouter::MEMORY_INSTRUCTIONS, $direct->instructions);

        $useTool = $router->route($this->userMessage('Use a ferramenta de memória.'));
        $this->assertTrue($useTool->agent->is($this->defaultAgent));

        $delegate = $router->route($this->userMessage('Faça uma pesquisa sobre isso.'));
        $this->assertTrue($delegate->agent->is($this->researchAgent));

        // long_task runs through the suggested agent until Phase 13's TaskService exists.
        $longTask = $router->route($this->userMessage('Quero um relatório completo.'));
        $this->assertTrue($longTask->agent->is($this->researchAgent));
    }

    public function test_records_routing_decision_in_message_meta(): void
    {
        IntentClassifier::fake(fn (): array => [
            'intent' => 'delegate_agent',
            'agent_slug' => 'research',
            'confidence' => 0.9,
        ]);

        $message = $this->userMessage('Pesquise sobre o assunto.');

        app(IntentRouter::class)->route($message);

        $this->assertEquals([
            'intent' => 'delegate_agent',
            'agent' => 'research',
            'confidence' => 0.9,
            'model' => $this->defaultModel->model_identifier,
        ], $message->fresh()->meta['routing']);
    }

    public function test_explicit_agent_prefix_overrides_classifier(): void
    {
        $message = $this->userMessage('@research Explique a arquitetura.');

        $routed = app(IntentRouter::class)->route($message);

        $this->assertTrue($routed->agent->is($this->researchAgent));
        $this->assertSame('Explique a arquitetura.', $routed->prompt);
        $this->assertEquals([
            'intent' => 'delegate_agent',
            'agent' => 'research',
            'confidence' => 1.0,
            'model' => null,
        ], $message->fresh()->meta['routing']);

        Ai::assertAgentNeverPrompted(IntentClassifier::class);
    }

    public function test_unknown_agent_prefix_falls_back_to_default(): void
    {
        $message = $this->userMessage('@inexistente Faça algo útil.');

        $routed = app(IntentRouter::class)->route($message);

        $this->assertTrue($routed->agent->is($this->defaultAgent));
        $this->assertEquals([
            'intent' => 'delegate_agent',
            'agent' => 'personal-assistant',
            'confidence' => 1.0,
            'model' => null,
        ], $message->fresh()->meta['routing']);
    }

    public function test_select_agent_uses_prefix_heuristics_and_default(): void
    {
        IntentClassifier::fake(function (string $prompt): array {
            return match (true) {
                str_contains($prompt, 'classificador') => ['intent' => 'delegate_agent', 'agent_slug' => 'research', 'confidence' => 0.9],
                str_contains($prompt, 'conversa') => ['intent' => 'direct_answer', 'agent_slug' => null, 'confidence' => 0.9],
                default => ['intent' => 'invalido', 'agent_slug' => null, 'confidence' => 0.1],
            };
        });

        $router = app(IntentRouter::class);

        $this->assertTrue($router->selectAgent('@research Busque a aula de inglês.')->is($this->researchAgent));
        $this->assertTrue($router->selectAgent('@inexistente Faça algo útil.')->is($this->defaultAgent));
        $this->assertTrue($router->selectAgent('Use o classificador para isso.')->is($this->researchAgent));

        // direct_answer still becomes a task: the Realtime session already decided it needs tools.
        $this->assertTrue($router->selectAgent('Só uma conversa rápida.')->is($this->defaultAgent));

        // Invalid classifier output falls back to the keyword heuristics.
        $this->assertTrue($router->selectAgent('Pesquise o preço do dólar.')->is($this->researchAgent));
        $this->assertTrue($router->selectAgent('Conta uma piada.')->is($this->defaultAgent));

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_whatsapp_keyword_routes_to_communication_in_heuristic_fallback(): void
    {
        $communicationAgent = Agent::factory()->create(['slug' => 'communication', 'name' => 'Communication']);

        IntentClassifier::fake(fn (): array => ['intent' => 'invalido', 'agent_slug' => null, 'confidence' => 0.1]);

        $message = $this->userMessage('Mande no WhatsApp que chego às 18h.');

        $routed = app(IntentRouter::class)->route($message);

        $this->assertTrue($routed->agent->is($communicationAgent));
        $this->assertSame('delegate_agent', $message->fresh()->meta['routing']['intent']);
        $this->assertSame('communication', $message->fresh()->meta['routing']['agent']);
        $this->assertTrue(app(IntentRouter::class)->selectAgent('mande no whatsapp')->is($communicationAgent));
    }

    private function userMessage(string $content): Message
    {
        $conversation = Conversation::factory()->for($this->user)->create();

        return Message::factory()->for($conversation)->for($this->user)->fromUser()->create(['content' => $content]);
    }
}
