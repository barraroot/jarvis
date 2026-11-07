<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The session cannot be paired in its current state — connected, suspended
 * or in error (RF-05): the API maps it to 409.
 */
class WhatsappSessionStateException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('whatsapp.session.not_pairable'));
    }
}
