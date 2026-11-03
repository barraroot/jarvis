<?php

namespace App\Support;

/**
 * Provider-safe tool names: catalogue identifiers such as `memory.remember`,
 * `delegate.<slug>` or `mcp.<server>.<tool>` keep their dots everywhere in
 * JARVIS, but Anthropic, OpenAI and DeepSeek reject any tool name outside
 * `^[a-zA-Z0-9_-]{1,64}$` with HTTP 400. Every name advertised to the SDK
 * goes through {@see self::forProvider()}.
 */
final class ToolName
{
    /**
     * The strictest limit among the supported providers (OpenAI).
     */
    public const int MAX_LENGTH = 64;

    public const string PATTERN = '/^[a-zA-Z0-9_-]{1,64}$/';

    /**
     * Replaces every invalid character with `_`; names over the limit are
     * truncated and suffixed with a short hash of the identifier, so two long
     * identifiers sharing a prefix never collide.
     */
    public static function forProvider(string $identifier): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $identifier) ?: 'tool';

        if (strlen($name) <= self::MAX_LENGTH) {
            return $name;
        }

        $hash = substr(hash('sha256', $identifier), 0, 8);

        return substr($name, 0, self::MAX_LENGTH - strlen($hash) - 1).'_'.$hash;
    }
}
