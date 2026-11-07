<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Any failure talking to wuzapi (HTTP ≥ 400, connection error, timeout). The
 * message is a generic pt-BR text; `upstreamDetail` carries the truncated
 * upstream reason with every known secret replaced by `[redacted]` (RF-18).
 * It never chains the transport exception, whose message may echo headers.
 */
class WuzapiRequestException extends RuntimeException
{
    public function __construct(
        public readonly string $upstreamDetail,
        public readonly ?int $status = null,
    ) {
        parent::__construct(WhatsappOperationException::translate(WhatsappOperationException::UPSTREAM_ERROR));
    }
}
