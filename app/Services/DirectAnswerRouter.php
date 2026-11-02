<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;

/**
 * Phase 7 routing: every message is answered directly, with the model chosen
 * for the conversation (or the global default). The system instructions cover
 * the explicit memory request of US-6.1: the model calls `memory.remember`
 * only when the user clearly asked to remember something, asking for
 * clarification otherwise.
 */
class DirectAnswerRouter implements MessageRouter
{
    public const string MEMORY_INSTRUCTIONS = 'Quando o usuário pedir explicitamente para você lembrar algo ("lembre que...", "lembrar de...", "guarde que..."), chame a ferramenta memory.remember com o conteúdo exato que o usuário afirmou. Se o pedido for ambíguo ou incompleto, peça esclarecimento antes de chamar a ferramenta. Nunca chame a ferramenta com conteúdo que o usuário não tenha afirmado claramente.';

    public function __construct(private readonly ModelSelector $modelSelector) {}

    public function route(Message $message): RoutedMessage
    {
        $conversation = $message->conversation()->withTrashed()->firstOrFail();

        return new RoutedMessage(
            $this->modelSelector->forConversation($conversation),
            $this->instructionsFor($message),
        );
    }

    /**
     * The `/lembrar` footer shortcut (US-6.1) forces the tool call: the text
     * after the prefix is an explicit, unambiguous memory request.
     */
    private function instructionsFor(Message $message): string
    {
        if (str_starts_with(trim($message->content), '/lembrar')) {
            return self::MEMORY_INSTRUCTIONS.' O usuário usou o atalho "/lembrar": chame imediatamente a ferramenta memory.remember com o texto após o prefixo, sem pedir esclarecimento.';
        }

        return self::MEMORY_INSTRUCTIONS;
    }
}
