<?php

namespace App\Tools;

use App\Models\MemoryCategory;
use App\Models\MemoryStatus;
use App\Models\Message;
use App\Models\User;
use App\Services\MemoryService;
use App\Support\ToolName;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Stringable;

/**
 * Internal `memory.remember` tool (US-6.1): the model calls it when the user
 * explicitly asks JARVIS to remember something ("lembre que..."). The memory is
 * stored as `confirmed`, sourced from the triggering message.
 */
class RememberMemoryTool implements Tool
{
    public function __construct(
        private readonly MemoryService $memoryService,
        private readonly ?User $user = null,
        private readonly ?Message $source = null,
    ) {}

    public function name(): string
    {
        return ToolName::forProvider('memory.remember');
    }

    public function description(): string
    {
        return 'Salva uma informação na memória de longo prazo do usuário. '
            .'Use somente quando o usuário pedir explicitamente para lembrar algo. '
            .'Se o pedido for ambíguo, peça esclarecimento antes de chamar.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'content' => $schema->string()->min(1)->max(2000)->required(),
            'category' => $schema->string()->enum([
                MemoryCategory::PREFERENCE,
                MemoryCategory::PROJECT,
                MemoryCategory::CONTEXT,
                MemoryCategory::LEARNING,
                MemoryCategory::FACT,
            ])->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $input = $request->validate([
            'content' => ['required', 'string', 'min:1', 'max:2000'],
            'category' => ['required', 'string', Rule::in([
                MemoryCategory::PREFERENCE,
                MemoryCategory::PROJECT,
                MemoryCategory::CONTEXT,
                MemoryCategory::LEARNING,
                MemoryCategory::FACT,
            ])],
        ]);

        $user = $this->user ?? throw new RuntimeException('The memory.remember tool needs a bound user.');

        $memory = $this->memoryService->remember(
            $user,
            $input['content'],
            $input['category'],
            MemoryStatus::CONFIRMED,
            $this->source,
            explicit: true,
        );

        return $memory->wasRecentlyCreated
            ? "Memória salva: {$memory->content}"
            : "Memória atualizada: {$memory->content}";
    }
}
