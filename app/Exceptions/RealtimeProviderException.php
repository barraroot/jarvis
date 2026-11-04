<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The Realtime provider refused or failed to issue a client secret: the API
 * answers 503 with a generic pt-BR message, never the provider's body (RF-09).
 */
class RealtimeProviderException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(__('voice.errors.provider_failed'), 0, $previous);
    }
}
