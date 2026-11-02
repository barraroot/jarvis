<?php

namespace App\Tools\Internal;

use App\Services\TaskContext;
use App\Tools\Contracts\JarvisTool;
use App\Tools\Internal\Concerns\InteractsAsInternalTool;
use App\Tools\ToolContext;
use RuntimeException;

/**
 * Internal `task.set_progress` tool (US-10.1): the agent reports the task
 * progress (0–100). Monotonic by design — the recorded value never decreases.
 */
class SetTaskProgressTool implements JarvisTool
{
    use InteractsAsInternalTool;

    public function identifier(): string
    {
        return 'task.set_progress';
    }

    public function name(): string
    {
        return 'Atualizar progresso da tarefa';
    }

    public function description(): string
    {
        return 'Atualiza o progresso da tarefa em execução (0 a 100), '
            .'de preferência após concluir cada etapa com task.finish_step.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'progress' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
            ],
            'required' => ['progress'],
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'progress' => ['type' => 'integer'],
            ],
            'required' => ['progress'],
        ];
    }

    public function isSensitive(): bool
    {
        return false;
    }

    public function handle(array $input, ToolContext $context): array
    {
        if ($context->task === null || ! app()->bound(TaskContext::class)) {
            throw new RuntimeException('A ferramenta task.set_progress só pode ser usada dentro de uma tarefa em execução.');
        }

        app(TaskContext::class)->setProgress((int) $input['progress']);

        return ['progress' => $context->task->fresh()->progress];
    }
}
