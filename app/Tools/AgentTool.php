<?php

namespace App\Tools;

use App\Models\Agent;
use App\Models\Tool;
use App\Services\DelegationService;
use App\Support\ToolName;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool as SdkTool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Exposes another catalogue agent as a tool named `delegate.<slug>` (US-7.3,
 * workflow 7): the model hands over a self-contained task and receives the
 * child agent's reply. The delegation itself — depth, cycle, availability and
 * remaining-time checks — runs in {@see DelegationService}; a blocked or
 * failed delegation is returned as a tool result, never as an exception that
 * would break the parent run.
 */
class AgentTool implements SdkTool
{
    public function __construct(
        private readonly Tool $tool,
        private readonly Agent $target,
        private readonly ToolContext $context,
        private readonly DelegationService $delegation,
    ) {}

    public function name(): string
    {
        return ToolName::forProvider($this->tool->identifier);
    }

    public function description(): string
    {
        return $this->tool->description;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()
                ->min(1)
                ->max(8000)
                ->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->delegation->delegate(
            $this->target,
            trim((string) $request['task']),
            $this->context,
        );
    }
}
