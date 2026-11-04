<?php

namespace App\Tools\Internal;

use App\Services\TaskContext;
use App\Services\VisualizationService;
use App\Tools\Contracts\JarvisTool;
use App\Tools\Internal\Concerns\InteractsAsInternalTool;
use App\Tools\ToolContext;
use RuntimeException;

/**
 * Internal `visualization.update_section` tool (RF-16, RF-16a, RF-17): the
 * agent of a voice task publishes one section of the HUD visualization panel.
 * It runs without approval — it only writes the task owner's own panel —
 * and never receives a visualization id from the model: the target is
 * always the visualization of the running task, so another user's
 * visualization can never be reached.
 */
class UpdateVisualizationSectionTool implements JarvisTool
{
    use InteractsAsInternalTool;

    public function __construct(private readonly VisualizationService $visualizations) {}

    public function identifier(): string
    {
        return 'visualization.update_section';
    }

    public function name(): string
    {
        return 'Atualizar seção da visualização';
    }

    public function description(): string
    {
        return 'Cria ou atualiza uma seção do painel de visualização do HUD da tarefa por voz em execução. '
            .'Seções: "question" (headline com a pergunta), "results" (lista de itens; marque o melhor com o badge "best") '
            .'e "text" (texto ou link). Repetir a mesma key atualiza a seção sem mudar sua posição.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'title' => ['type' => 'string', 'maxLength' => 160, 'description' => 'Título do painel.'],
                'source' => ['type' => 'string', 'maxLength' => 160, 'description' => 'Fonte dos dados (conector · ferramenta).'],
                'section' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'key' => ['type' => 'string', 'pattern' => '^[a-z_]{1,32}$'],
                        'label' => ['type' => 'string', 'maxLength' => 40],
                        'type' => ['type' => 'string', 'enum' => VisualizationService::SECTION_TYPES],
                        'headline' => ['type' => 'string', 'maxLength' => 300],
                        'status' => ['type' => 'string', 'enum' => ['syncing', 'updated']],
                        'items' => [
                            'type' => 'array',
                            'maxItems' => 10,
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'title' => ['type' => 'string', 'maxLength' => 200],
                                    'subtitle' => ['type' => 'string', 'maxLength' => 200],
                                    'badges' => [
                                        'type' => 'array',
                                        'maxItems' => 4,
                                        'items' => ['type' => 'string', 'maxLength' => 20],
                                    ],
                                ],
                                'required' => ['title'],
                            ],
                        ],
                        'text' => ['type' => 'string', 'maxLength' => 4000],
                    ],
                    'required' => ['key', 'label', 'type'],
                ],
            ],
            'required' => ['section'],
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'visualization_id' => ['type' => 'integer'],
                'section_key' => ['type' => 'string'],
                'position' => ['type' => 'integer'],
            ],
            'required' => ['visualization_id', 'section_key', 'position'],
        ];
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function isSensitive(): bool
    {
        return false;
    }

    public function handle(array $input, ToolContext $context): array
    {
        if ($context->task === null || ! app()->bound(TaskContext::class)) {
            throw new RuntimeException('A ferramenta visualization.update_section só pode ser usada dentro de uma tarefa em execução.');
        }

        $task = $context->task->fresh();

        if ($task === null || $task->user_id !== $context->user->id) {
            throw new RuntimeException('A tarefa não pertence ao operador desta execução.');
        }

        $visualization = $this->visualizations->upsertSection($task, $input);

        $key = $input['section']['key'];
        $section = collect($visualization->sections)->firstWhere('key', $key);

        return [
            'visualization_id' => $visualization->id,
            'section_key' => $key,
            'position' => (int) ($section['position'] ?? 0),
        ];
    }
}
