<?php

namespace App\Tools;

use App\Models\Approval;
use App\Models\Tool;
use App\Services\ApprovalService;
use App\Tools\Exceptions\ToolNotAuthorizedException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Deserializer;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Approvals\Approval as SdkApproval;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool as SdkTool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Adapts a catalogue `Tool` for consumption by `laravel/ai` agents (US-8.1):
 * it advertises the tool schema to the model and runs it through the Tool
 * Engine. When the tool requires approval, it is also `Approvable`, so the
 * SDK pauses the run and only calls back after the owner decides.
 */
class ToolAdapter implements Approvable, SdkTool
{
    /**
     * Approval opened by `shouldRequestApproval`; linked to the execution
     * when the SDK resumes with an approve decision.
     */
    private ?Approval $pendingApproval = null;

    /**
     * When set, approval is required regardless of the catalogue flags.
     */
    private ?string $approvalOverride = null;

    private bool $approvalDisabled = false;

    public function __construct(
        private readonly Tool $tool,
        private readonly ToolExecutor $executor,
        private readonly ApprovalService $approvals,
        private readonly ToolContext $context,
    ) {}

    public function requireApproval(?string $reason = null): static
    {
        $this->approvalDisabled = false;
        $this->approvalOverride = $reason;

        return $this;
    }

    public function withoutApproval(): static
    {
        $this->approvalDisabled = true;
        $this->approvalOverride = null;

        return $this;
    }

    public function name(): string
    {
        return $this->tool->identifier;
    }

    public function description(): string
    {
        return $this->tool->description;
    }

    /**
     * The input schema mapped to SDK types, property by property, mirroring
     * the SDK's own object deserialization so `required` survives.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $types = [];
        $required = array_flip(array_map('strval', (array) ($this->tool->input_schema['required'] ?? [])));

        foreach (($this->tool->input_schema['properties'] ?? []) as $property => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $type = Deserializer::deserialize($definition);

            if (isset($required[(string) $property])) {
                $type->required();
            }

            $types[$property] = $type;
        }

        return $types;
    }

    public function shouldRequestApproval(Request $request): ?SdkApproval
    {
        if ($this->approvalDisabled) {
            return null;
        }

        if ($this->approvalOverride === null && ! $this->tool->requiresApproval()) {
            return null;
        }

        $this->pendingApproval = $this->approvals->request($this->tool, $request->all(), $this->context);

        return SdkApproval::required($this->approvalOverride ?? $this->approvalReason());
    }

    public function handle(Request $request): Stringable|string
    {
        $context = $this->pendingApproval !== null
            ? $this->context->withApproval($this->pendingApproval)
            : $this->context;

        try {
            $result = $this->executor->execute($this->tool, $request->all(), $context);
        } catch (ToolNotAuthorizedException $exception) {
            return '<tool_result name="'.$this->tool->identifier.'" trusted="false">'
                .json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE)
                .'</tool_result>';
        }

        if ($result->isPendingApproval()) {
            return '<tool_result name="'.$this->tool->identifier.'" trusted="false">'
                .json_encode(['status' => 'pending_approval'], JSON_UNESCAPED_UNICODE)
                .'</tool_result>';
        }

        return $result->forModel();
    }

    private function approvalReason(): string
    {
        $effects = [];

        if ($this->tool->is_write) {
            $effects[] = 'escrita';
        }

        if ($this->tool->is_sensitive) {
            $effects[] = 'sensível';
        }

        $effects[] = 'requer aprovação explícita do dono';

        return 'Esta ferramenta é '.implode(' e ', $effects).' antes de executar.';
    }
}
