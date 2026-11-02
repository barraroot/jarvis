<?php

namespace App\Tools\Internal;

use App\Models\TaskStatus;
use App\Services\TaskContext;
use App\Tools\Contracts\JarvisTool;
use App\Tools\Internal\Concerns\InteractsAsInternalTool;
use App\Tools\ToolContext;
use RuntimeException;

/**
 * Internal `task.finish_step` tool (US-10.1): concludes a step opened with
 * `task.start_step`, carrying its structured output (or error).
 */
class FinishTaskStepTool implements JarvisTool
{
    use InteractsAsInternalTool;

    public function identifier(): string
    {
        return 'task.finish_step';
    }

    public function name(): string
    {
        return 'Concluir etapa da tarefa';
    }

    public function description(): string
    {
        return 'Conclui uma etapa da tarefa aberta com task.start_step, '
            .'registrando o resultado (ex.: contagem de registros afetados) ou o erro.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                'output' => ['type' => 'object'],
                'error' => ['type' => 'string'],
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
                'status' => ['type' => 'string'],
            ],
            'required' => ['position', 'name', 'status'],
        ];
    }

    public function isSensitive(): bool
    {
        return false;
    }

    public function handle(array $input, ToolContext $context): array
    {
        $taskContext = $this->taskContext($context);

        $step = $context->task->steps()
            ->where('name', $input['name'])
            ->whereNull('finished_at')
            ->orderByDesc('position')
            ->first();

        if ($step === null) {
            throw new RuntimeException("Etapa [{$input['name']}] não está aberta nesta tarefa.");
        }

        $step = isset($input['error'])
            ? $taskContext->failStep($step, $input['error'])
            : $taskContext->finishStep($step, $input['output'] ?? null);

        return [
            'position' => $step->position,
            'name' => $step->name,
            'status' => $step->fresh()->status->slug ?? TaskStatus::COMPLETED,
        ];
    }

    private function taskContext(ToolContext $context): TaskContext
    {
        if ($context->task === null || ! app()->bound(TaskContext::class)) {
            throw new RuntimeException('A ferramenta task.finish_step só pode ser usada dentro de uma tarefa em execução.');
        }

        return app(TaskContext::class);
    }
}
