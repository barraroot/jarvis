<?php

namespace App\Console\Commands;

use App\Models\Tool;
use App\Models\ToolType;
use App\Support\Settings;
use App\Tools\Contracts\JarvisTool;
use App\Tools\InternalToolRegistry;
use Illuminate\Console\Command;

/**
 * Registers or refreshes the catalogue rows for every internal tool class
 * (US-8.1). Idempotent: repeated runs converge to the same rows, and tools
 * never created through the admin keep their activation flag.
 */
class SyncInternalTools extends Command
{
    protected $signature = 'jarvis:tools:sync-internal';

    protected $description = 'Register internal tool classes in the tools catalogue';

    public function handle(InternalToolRegistry $registry): int
    {
        $created = 0;
        $updated = 0;

        foreach ($registry->all() as $identifier => $tool) {
            $existing = Tool::query()->withTrashed()->where('identifier', $identifier)->first();

            if ($existing === null) {
                Tool::query()->create($this->attributesFor($tool));
                $created++;
                $this->line("Registered <info>{$identifier}</info>");

                continue;
            }

            if ($existing->trashed()) {
                $existing->restore();
            }

            $existing->fill($this->attributesFor($tool));
            $this->discardReorderedSchemas($existing);
            $existing->save();
            $updated++;
            $this->line("Updated <info>{$identifier}</info>");
        }

        $this->info("Internal tools synced: {$created} created, {$updated} updated.");

        return self::SUCCESS;
    }

    /**
     * `jsonb` stores object keys in its own order, so a strict cast comparison
     * flags unchanged schemas as dirty and bumps `updated_at`. Loose equality
     * ignores key order, keeping repeated runs a true no-op.
     */
    private function discardReorderedSchemas(Tool $tool): void
    {
        foreach (['input_schema', 'output_schema'] as $attribute) {
            if ($tool->isDirty($attribute) && $tool->getOriginal($attribute) == $tool->getAttribute($attribute)) {
                $tool->syncOriginalAttribute($attribute);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesFor(JarvisTool $tool): array
    {
        return [
            'tool_type_id' => ToolType::idFor(ToolType::INTERNAL),
            'identifier' => $tool->identifier(),
            'name' => $tool->name(),
            'description' => $tool->description(),
            'input_schema' => $tool->inputSchema(),
            'output_schema' => $tool->outputSchema(),
            'timeout_seconds' => method_exists($tool, 'timeoutSeconds')
                ? $tool->timeoutSeconds()
                : app(Settings::class)->toolTimeoutSeconds(),
            'is_write' => ! method_exists($tool, 'isWrite') || $tool->isWrite(),
            'is_sensitive' => ! method_exists($tool, 'isSensitive') || $tool->isSensitive(),
            'handler_class' => $tool::class,
        ];
    }
}
