<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * No usable Realtime model (missing default, provider without key or not
 * OpenAI): voice stays unavailable and the API answers 409 (RF-08a, RF-09a).
 */
class RealtimeUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('voice.errors.realtime_unavailable'));
    }
}
