<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The schedule cannot take the requested transition in its current status —
 * cancelling one that is not `active|paused` (RF-33) or resuming one that is
 * not `paused` (RF-43): the API maps it to 409.
 */
class WhatsappScheduleStateException extends RuntimeException
{
    public static function notCancellable(): self
    {
        return new self(__('whatsapp.schedules.not_cancellable'));
    }

    public static function notResumable(): self
    {
        return new self(__('whatsapp.schedules.not_resumable'));
    }
}
