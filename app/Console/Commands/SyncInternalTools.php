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

            $existing->update($this->attributesFor($tool));
            $updated++;
            $this->line("Updated <info>{$identifier}</info>");
        }

        $this->info("Internal tools synced: {$created} created, {$updated} updated.");

        return self::SUCCESS;
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
