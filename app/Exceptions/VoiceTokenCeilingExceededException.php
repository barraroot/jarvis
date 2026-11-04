<?php

namespace App\Exceptions;

use App\Models\VoiceSession;
use RuntimeException;

/**
 * A usage report would push the voice session past its token ceiling
 * (RF-10a, D1): the API answers 422 and nothing is recorded.
 */
class VoiceTokenCeilingExceededException extends RuntimeException
{
    public function __construct(public readonly VoiceSession $voiceSession, public readonly int $ceiling)
    {
        parent::__construct(__('voice.errors.session_token_ceiling_exceeded'));
    }
}
