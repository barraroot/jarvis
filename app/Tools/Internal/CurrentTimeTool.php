<?php

namespace App\Tools\Internal;

use App\Tools\Contracts\JarvisTool;
use App\Tools\Internal\Concerns\InteractsAsInternalTool;
use App\Tools\ToolContext;
use DateTimeZone;
use Throwable;

/**
 * Read-only `time.current` tool: current date/time in an optional IANA
 * timezone. Used as the reference internal tool for catalogue sync tests.
 */
class CurrentTimeTool implements JarvisTool
{
    use InteractsAsInternalTool;

    public function identifier(): string
    {
        return 'time.current';
    }

    public function name(): string
    {
        return 'Hora atual';
    }

    public function description(): string
    {
        return 'Retorna a data e hora atuais do servidor JARVIS, opcionalmente em outro fuso horário.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'timezone' => [
                    'type' => 'string',
                    'description' => 'Fuso IANA opcional (ex.: America/Sao_Paulo).',
                ],
            ],
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'iso' => ['type' => 'string'],
                'formatted' => ['type' => 'string'],
                'timezone' => ['type' => 'string'],
                'unix' => ['type' => 'integer'],
            ],
            'required' => ['iso', 'formatted', 'timezone', 'unix'],
        ];
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function isSensitive(): bool
    {
        return false;
    }

    public function handle(array $input, ToolContext $context): array
    {
        $timezone = $input['timezone'] ?? config('app.timezone');

        try {
            $now = now()->setTimezone(new DateTimeZone($timezone));
        } catch (Throwable) {
            $now = now()->setTimezone(config('app.timezone'));
            $timezone = config('app.timezone');
        }

        return [
            'iso' => $now->toIso8601String(),
            'formatted' => $now->format('d/m/Y H:i:s'),
            'timezone' => (string) $timezone,
            'unix' => $now->timestamp,
        ];
    }
}
