<?php

namespace App\Tools\Internal;

use App\Services\TaskContext;
use App\Tools\Contracts\JarvisTool;
use App\Tools\Internal\Concerns\InteractsAsInternalTool;
use App\Tools\ToolContext;
use RuntimeException;

/**
 * Internal `task.start_step` tool (US-10.1): the agent opens a named step of
 * its task; positions are sequential and steps are idempotent by name, so
 * resumed runs never repeat concluded work.
 */
class StartTaskStepTool implements JarvisTool
{
    use InteractsAsInternalTool;

    public function identifier(): string
    {
        return 'task.start_step';
    }

    public function name(): string
    {
        return 'Iniciar etapa da tarefa';
    }

    public function description(): string
    {
        return 'Registra o início de uma etapa da tarefa em execução. '
            .'Use uma etapa por bloco coerente de trabalho (ex.: "Buscar clientes", "Enviar lembretes"). '
            .'Chame task.finish_step ao concluir.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
            ],
            'required' => ['name'],
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'position' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
            ],
            'required' => ['position', 'name'],
        ];
    }

    public function isSensitive(): bool
    {
        return false;
    }

    public function handle(array $input, ToolContext $context): array
    {
        $step = $this->taskContext($context)->startStep($input['name']);

        return [
            'position' => $step->position,
            'name' => $step->name,
        ];
    }

    private function taskContext(ToolContext $context): TaskContext
    {
        if ($context->task === null || ! app()->bound(TaskContext::class)) {
            throw new RuntimeException('A ferramenta task.start_step só pode ser usada dentro de uma tarefa em execução.');
        }

        return app(TaskContext::class);
    }
}
