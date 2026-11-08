<?php

namespace App\Services;

use App\Ai\Agents\RuntimeAgent;
use App\Models\Agent;
use App\Models\Tool;
use App\Support\Settings;
use App\Support\UntrustedContent;
use App\Tools\AgentTool;
use App\Tools\ToolAdapter;
use App\Tools\ToolContext;
use App\Tools\ToolExecutor;
use Laravel\Ai\Messages\Message as AiMessage;

/**
 * Builds the `laravel/ai` runtime agent for a catalogue agent (US-7.3): its
 * instructions (name, objective, pt-BR instructions), the effective model's
 * context messages and exactly its authorized, active tools — catalogue tools
 * via {@see ToolAdapter} and `delegate.<slug>` rows via {@see AgentTool}.
 * Anything not authorized is never exposed to the model.
 */
class AgentRuntimeFactory
{
    public function __construct(
        private readonly ToolExecutor $executor,
        private readonly ApprovalService $approvals,
        private readonly Settings $settings,
    ) {}

    /**
     * @param  list<array{role: string, content: string}>  $messages  Context (history + context sources) for the model.
     */
    public function make(Agent $agent, ToolContext $context, array $messages = []): RuntimeAgent
    {
        return new RuntimeAgent(
            $this->instructions($agent),
            array_map(
                fn (array $message): AiMessage => new AiMessage($message['role'], $message['content']),
                $messages,
            ),
            $this->tools($agent, $context),
            $this->toolCallLimit($agent),
        );
    }

    /**
     * The agent's own limit when set, otherwise the global setting (US-7.3).
     */
    public function toolCallLimit(Agent $agent): int
    {
        return $agent->max_tool_calls > 0 ? $agent->max_tool_calls : $this->settings->maxToolCalls();
    }

    private function instructions(Agent $agent): string
    {
        return UntrustedContent::withPolicy("# {$agent->name}\n\nObjetivo: {$agent->objective}\n\n{$agent->instructions}");
    }

    /**
     * @return list<object>
     */
    private function tools(Agent $agent, ToolContext $context): array
    {
        return $agent->tools()
            ->active()
            ->with(['type', 'mcpToolDefinition.server'])
            ->get()
            ->map(function (Tool $tool) use ($context): ?object {
                if (str_starts_with($tool->identifier, 'delegate.')) {
                    $target = $this->delegationTarget($tool, $context);

                    if ($target === null) {
                        return null;
                    }

                    return new AgentTool($tool, $target, $context, app(DelegationService::class));
                }

                return new ToolAdapter($tool, $this->executor, $this->approvals, $context);
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Resolves the agent a `delegate.<slug>` tool points to. Missing,
     * deactivated or trashed targets are dropped from the model's tools.
     */
    private function delegationTarget(Tool $tool, ToolContext $context): ?Agent
    {
        $slug = substr($tool->identifier, strlen('delegate.'));

        $target = Agent::query()->active()->where('slug', $slug)->first();

        if ($target === null) {
            return null;
        }

        // An agent can never delegate to itself through its own tool list.
        $parent = $context->agentExecution;

        if ($parent !== null && $parent->agent_id === $target->id) {
            return null;
        }

        return $target;
    }
}
