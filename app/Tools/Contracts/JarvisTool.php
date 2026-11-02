<?php

namespace App\Tools\Contracts;

use App\Tools\ToolContext;

/**
 * Contract for tools executed in-process by the Tool Engine (US-8.1).
 *
 * Internal tools are classes in `App\Tools\Internal` referenced by
 * `tools.handler_class` and registered by `jarvis:tools:sync-internal`.
 * They never shell out: model input must not reach `shell_exec` or `Process`
 * (guarded by `NoArbitraryCommandsTest`).
 */
interface JarvisTool
{
    /**
     * Stable catalogue identifier (e.g. `time.current`).
     */
    public function identifier(): string;

    /**
     * Human name shown in the admin and on approval cards.
     */
    public function name(): string;

    /**
     * Purpose, given to the model so it can pick the tool.
     */
    public function description(): string;

    /**
     * JSON Schema of the accepted input.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * JSON Schema of the produced output.
     *
     * @return array<string, mixed>
     */
    public function outputSchema(): array;

    /**
     * Run the tool. Input is already validated against `inputSchema()`.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function handle(array $input, ToolContext $context): array;
}
