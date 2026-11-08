<?php

namespace App\Services;

use App\Ai\Agents\IntentClassifier;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

/**
 * Phase 12 routing (US-3.3, workflow 2 step 3, workflow 7 step 2): classifies
 * each user message — structured output from the default model, with a
 * keyword-heuristic fallback — into `direct_answer`, `use_tool`,
 * `delegate_agent` or `long_task`. Direct answers keep the Phase 7
 * `BrainClient` path; tool use, delegation and (until Phase 13) long tasks run
 * through `AgentRunner`. The decision is recorded on `messages.meta.routing`,
 * and an explicit `@slug` prefix overrides the classifier.
 */
class IntentRouter implements MessageRouter
{
    public const string DIRECT_ANSWER = 'direct_answer';

    public const string USE_TOOL = 'use_tool';

    public const string DELEGATE_AGENT = 'delegate_agent';

    public const string LONG_TASK = 'long_task';

    /**
     * @var list<string>
     */
    public const array INTENTS = [
        self::DIRECT_ANSWER,
        self::USE_TOOL,
        self::DELEGATE_AGENT,
        self::LONG_TASK,
    ];

    /**
     * Confidence recorded when the classification comes from keywords (no model call).
     */
    public const float HEURISTIC_CONFIDENCE = 0.5;

    /**
     * Keyword → [intent, suggested agent] pairs evaluated in order (pt-BR).
     *
     * @var list<array{keyword: string, intent: string, agent: string}>
     */
    private const array HEURISTICS = [
        ['keyword' => 'relatório', 'intent' => self::LONG_TASK, 'agent' => 'research'],
        ['keyword' => 'levantamento', 'intent' => self::LONG_TASK, 'agent' => 'research'],
        ['keyword' => 'auditoria', 'intent' => self::LONG_TASK, 'agent' => 'research'],
        ['keyword' => 'pesquisa aprofundada', 'intent' => self::LONG_TASK, 'agent' => 'research'],
        ['keyword' => 'análise aprofundada', 'intent' => self::LONG_TASK, 'agent' => 'research'],
        ['keyword' => 'tarefa longa', 'intent' => self::LONG_TASK, 'agent' => 'research'],
        ['keyword' => 'lembre que', 'intent' => self::USE_TOOL, 'agent' => 'personal-assistant'],
        ['keyword' => 'guarde que', 'intent' => self::USE_TOOL, 'agent' => 'personal-assistant'],
        ['keyword' => 'que horas', 'intent' => self::USE_TOOL, 'agent' => 'personal-assistant'],
        ['keyword' => 'hora atual', 'intent' => self::USE_TOOL, 'agent' => 'personal-assistant'],
        ['keyword' => 'data atual', 'intent' => self::USE_TOOL, 'agent' => 'personal-assistant'],
        ['keyword' => 'escreva código', 'intent' => self::DELEGATE_AGENT, 'agent' => 'developer'],
        ['keyword' => 'revise o código', 'intent' => self::DELEGATE_AGENT, 'agent' => 'developer'],
        ['keyword' => 'automatize', 'intent' => self::DELEGATE_AGENT, 'agent' => 'automation'],
        ['keyword' => 'redija', 'intent' => self::DELEGATE_AGENT, 'agent' => 'communication'],
        ['keyword' => 'pesquise', 'intent' => self::DELEGATE_AGENT, 'agent' => 'research'],
        ['keyword' => 'whatsapp', 'intent' => self::DELEGATE_AGENT, 'agent' => 'communication'],
    ];

    public function __construct(
        private readonly ModelSelector $modelSelector,
        private readonly ProviderRegistry $providerRegistry,
    ) {}

    public function route(Message $message): RoutedMessage
    {
        $conversation = $message->conversation()->withTrashed()->firstOrFail();

        $prefix = $this->explicitAgentPrefix($message->content);

        if ($prefix !== null) {
            return $this->routeExplicitAgent($conversation, $message, $prefix['slug'], $prefix['prompt']);
        }

        $decision = $this->classify($message->content);

        $intent = $decision['intent'];
        $agentSlug = $decision['agent'];
        $confidence = $decision['confidence'];
        $classificationModel = $decision['model'];

        $agent = $intent === self::DIRECT_ANSWER
            ? null
            : $this->resolveAgent($agentSlug);

        $this->recordRouting($message, [
            'intent' => $agent === null ? self::DIRECT_ANSWER : $intent,
            'agent' => $agent?->slug,
            'confidence' => $confidence,
            'model' => $classificationModel,
        ]);

        if ($agent === null) {
            return new RoutedMessage(
                $this->modelSelector->forConversation($conversation),
                DirectAnswerRouter::MEMORY_INSTRUCTIONS,
            );
        }

        return new RoutedMessage(
            $this->modelSelector->forConversation($conversation, $agent),
            null,
            $agent,
        );
    }

    /**
     * Agent for a request that is already known to be a task (voice, CT-02):
     * same `@slug` prefix, classifier, heuristics and default-agent rules as
     * `route()`, except that `direct_answer` also lands on the default agent —
     * the Realtime session has already decided the request needs tools.
     *
     * @throws ModelNotFoundException<Agent>
     */
    public function selectAgent(string $content): Agent
    {
        $prefix = $this->explicitAgentPrefix($content);

        $agent = $prefix !== null
            ? $this->explicitAgent($prefix['slug'])
            : $this->resolveAgent($this->classify($content)['agent']) ?? $this->fallbackAgent();

        return $agent ?? throw (new ModelNotFoundException)->setModel(Agent::class);
    }

    /**
     * An explicit `@slug` prefix at the start of the message forces the agent
     * (validated against active agents); the prefix is stripped from the
     * prompt the model sees. Unknown slugs fall back to the default agent.
     *
     * @return array{slug: string, prompt: string}|null
     */
    private function explicitAgentPrefix(string $content): ?array
    {
        if (preg_match('/^@([a-z0-9-]+)(?:\s+(.+))?$/s', trim($content), $matches) !== 1) {
            return null;
        }

        return [
            'slug' => $matches[1],
            'prompt' => filled($matches[2] ?? null) ? $matches[2] : $content,
        ];
    }

    private function routeExplicitAgent(Conversation $conversation, Message $message, string $slug, string $prompt): RoutedMessage
    {
        $agent = $this->explicitAgent($slug);

        $this->recordRouting($message, [
            'intent' => self::DELEGATE_AGENT,
            'agent' => $agent?->slug,
            'confidence' => 1.0,
            'model' => null,
        ]);

        if ($agent === null) {
            return new RoutedMessage(
                $this->modelSelector->forConversation($conversation),
                DirectAnswerRouter::MEMORY_INSTRUCTIONS,
            );
        }

        return new RoutedMessage(
            $this->modelSelector->forConversation($conversation, $agent),
            null,
            $agent,
            $prompt,
        );
    }

    /**
     * The agent named by an `@slug` prefix when active, else the default
     * agent, else the `personal-assistant` fallback.
     */
    private function explicitAgent(string $slug): ?Agent
    {
        return Agent::query()->active()->where('slug', $slug)->first()
            ?? $this->defaultAgent()
            ?? $this->fallbackAgent();
    }

    /**
     * Structured classification by the default model; any failure — provider
     * error, no default model, invalid intent — falls back to keywords.
     *
     * @return array{intent: string, agent: string|null, confidence: float, model: string|null}
     */
    private function classify(string $content): array
    {
        try {
            $this->providerRegistry->register();

            $model = $this->modelSelector->defaultTextModel();

            if ($model === null) {
                throw new \RuntimeException('No default text model configured.');
            }

            $response = (new IntentClassifier)->prompt(
                $content,
                provider: $model->provider->slug,
                model: $model->model_identifier,
            );

            $structured = $response->structured ?? [];
            $intent = (string) ($structured['intent'] ?? '');

            if (! in_array($intent, self::INTENTS, true)) {
                throw new \RuntimeException("Invalid intent [{$intent}].");
            }

            $agentSlug = is_string($structured['agent_slug'] ?? null) ? $structured['agent_slug'] : null;

            if ($agentSlug === '') {
                $agentSlug = null;
            }

            return [
                'intent' => $intent,
                'agent' => $agentSlug,
                'confidence' => (float) ($structured['confidence'] ?? 0),
                'model' => $model->model_identifier,
            ];
        } catch (Throwable) {
            return [...$this->heuristics($content), 'model' => null];
        }
    }

    /**
     * @return array{intent: string, agent: string|null, confidence: float}
     */
    private function heuristics(string $content): array
    {
        $normalized = mb_strtolower(trim($content));

        foreach (self::HEURISTICS as $rule) {
            if (str_contains($normalized, $rule['keyword'])) {
                return [
                    'intent' => $rule['intent'],
                    'agent' => $rule['agent'],
                    'confidence' => self::HEURISTIC_CONFIDENCE,
                ];
            }
        }

        return ['intent' => self::DIRECT_ANSWER, 'agent' => null, 'confidence' => self::HEURISTIC_CONFIDENCE];
    }

    /**
     * The classifier's suggestion, only when the agent is active; otherwise
     * the default agent takes over.
     */
    private function resolveAgent(?string $slug): ?Agent
    {
        if ($slug !== null) {
            $suggested = Agent::query()->active()->where('slug', $slug)->first();

            if ($suggested !== null) {
                return $suggested;
            }
        }

        return $this->defaultAgent();
    }

    private function defaultAgent(): ?Agent
    {
        return Agent::query()->active()->where('is_default', true)->first();
    }

    private function fallbackAgent(): ?Agent
    {
        return Agent::query()->active()->where('slug', 'personal-assistant')->first();
    }

    /**
     * @param  array{intent: string, agent: string|null, confidence: float, model: string|null}  $routing
     */
    private function recordRouting(Message $message, array $routing): void
    {
        $message->update([
            'meta' => array_merge($message->meta ?? [], ['routing' => $routing]),
        ]);
    }
}
