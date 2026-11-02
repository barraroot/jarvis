<?php

namespace App\Tools\Internal;

use App\Services\TaskContext;
use App\Tools\Contracts\JarvisTool;
use App\Tools\Internal\Concerns\InteractsAsInternalTool;
use App\Tools\ToolContext;
use RuntimeException;

/**
 * Internal `task.log` tool (US-10.1): the agent appends a leveled log line
 * (`debug|info|warning|error`) to the task, optionally under a step.
 */
class TaskLogTool implements JarvisTool
{
    use InteractsAsInternalTool;

    public function identifier(): string
    {
        return 'task.log';
    }

    public function name(): string
    {
        return 'Registrar log da tarefa';
    }

    public function description(): string
    {
        return 'Adiciona uma linha de log à tarefa (debug, info, warning ou error), '
            .'para o operador acompanhar o andamento no detalhe da tarefa.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000],
                'level' => [
                    'type' => 'string',
                    'enum' => ['debug', 'info', 'warning', 'error'],
                    'default' => 'info',
                ],
                'step_name' => ['type' => 'string'],
            ],
            'required' => ['message'],
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'level' => ['type' => 'string'],
            ],
            'required' => ['id', 'level'],
        ];
    }

    public function isSensitive(): bool
    {
        return false;
    }

    public function handle(array $input, ToolContext $context): array
    {
        if ($context->task === null || ! app()->bound(TaskContext::class)) {
            throw new RuntimeException('A ferramenta task.log só pode ser usada dentro de uma tarefa em execução.');
        }

        $step = isset($input['step_name'])
            ? $context->task->steps()->where('name', $input['step_name'])->first()
            : null;

        $log = app(TaskContext::class)->log($input['message'], $input['level'] ?? 'info', $step);

        return [
            'id' => $log->id,
            'level' => $log->level->slug,
        ];
    }
}
