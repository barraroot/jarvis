<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Any failure talking to an MCP connector (transport, handshake, JSON-RPC,
 * remote tool error). The message never carries the connector credential.
 */
class McpConnectionException extends RuntimeException
{
    /**
     * Build the exception scrubbing the credential (and its Bearer form) out of the message.
     */
    public static function from(string $message, ?string $credential = null): self
    {
        if (filled($credential)) {
            $message = str_replace($credential, '****', $message);
        }

        return new self($message);
    }
}
