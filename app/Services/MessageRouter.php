<?php

namespace App\Services;

use App\Models\Message;

/**
 * Decides how a user message is answered: which model to use and which
 * instructions to send (US-3.3). This phase only answers directly; the intent
 * router (Phase 12) plugs in behind this interface.
 */
interface MessageRouter
{
    public function route(Message $message): RoutedMessage;
}
