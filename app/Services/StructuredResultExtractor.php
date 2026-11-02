<?php

namespace App\Services;

use App\Ai\StructuredResultSchema;
use App\Models\AiModel;
use App\Models\User;
use App\Results\ResultNormalizer;
use Laravel\Ai\Messages\Message as AiMessage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

use function Laravel\Ai\agent;

/**
 * Asks the model for a final structured result block after the streamed text
 * reply (US-11.1): the structured pass runs only when the answer contains
 * tabular/list markup or a tool was used — plain replies stay `text`. The raw
 * block is validated through {@see ResultNormalizer}, so anything invalid
 * falls back to a text payload flagged with `meta.result_invalid` instead of
 * being persisted as-is. Extraction failures (provider down, schema refusals)
 * return null and the caller keeps the streamed text as the result.
 */
class StructuredResultExtractor
{
    /**
     * Instructions of the formatting pass: the model only restates the final
     * answer as one of the schema branches, it never invents new content.
     */
    public const string INSTRUCTIONS = 'Você é o formatador de resultados do JARVIS. Converta a resposta final em um bloco estruturado escolhendo exatamente um dos tipos disponíveis, preservando o conteúdo. Use "text" apenas para respostas sem estrutura. Nunca interprete HTML; escreva Markdown puro nos campos de corpo.';

    public const string PROMPT = 'Estruture o resultado final acima em um dos formatos suportados.';

    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ResultNormalizer $normalizer,
    ) {}

    /**
     * Whether the reply warrants a structured block: tool results are always
     * mapped to a structured type (table/search_results/...) by the model,
     * never by the frontend; otherwise markdown tables or lists (three or
     * more bulleted/numbered lines) qualify.
     */
    public function shouldExtract(string $content, bool $toolsUsed): bool
    {
        if ($toolsUsed) {
            return true;
        }

        if (preg_match('/^\s*\|.+\|/m', $content) === 1) {
            return true;
        }

        return preg_match_all('/^\s*(?:[-*]|\d+\.)\s+\S/m', $content) >= 3;
    }

    /**
     * Runs the structured pass and returns the normalized `{type, payload}`
     * pair, or null when the pass is not needed or fails.
     *
     * @param  list<array{role: string, content: string}>  $history  Conversation context, oldest first.
     * @return array{type: string, payload: array<string, mixed>}|null
     */
    public function extract(User $user, AiModel $model, string $content, array $history, bool $toolsUsed): ?array
    {
        if (! $this->shouldExtract($content, $toolsUsed)) {
            return null;
        }

        try {
            $this->registry->register();

            $messages = [
                ...array_map(
                    static fn (array $message): AiMessage => new AiMessage($message['role'], $message['content']),
                    $history,
                ),
                new AiMessage('assistant', $content),
            ];

            $response = agent(
                instructions: self::INSTRUCTIONS,
                messages: $messages,
                schema: StructuredResultSchema::schema(...),
            )->prompt(
                self::PROMPT,
                provider: $model->provider->slug,
                model: $model->model_identifier,
            );

            $structured = $response instanceof StructuredAgentResponse ? $response->toArray() : null;
            $raw = is_array($structured) && is_array($structured['result'] ?? null) ? $structured['result'] : null;

            if ($raw === null) {
                return null;
            }

            return $this->normalizer->normalize($raw);
        } catch (Throwable) {
            return null;
        }
    }
}
