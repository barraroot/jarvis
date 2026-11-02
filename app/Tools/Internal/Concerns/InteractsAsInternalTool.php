<?php

namespace App\Tools\Internal\Concerns;

use App\Support\Settings;

/**
 * Safe-by-default metadata for internal tools: write and sensitive until the
 * class opts out (mirrors the MCP discovery default, US-9.3), with the global
 * tool timeout from the admin settings.
 */
trait InteractsAsInternalTool
{
    public function timeoutSeconds(): int
    {
        return app(Settings::class)->toolTimeoutSeconds();
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function isSensitive(): bool
    {
        return true;
    }
}
