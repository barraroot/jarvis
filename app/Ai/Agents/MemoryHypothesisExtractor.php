<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Reads the last user/assistant exchange and proposes up to 3 potentially
 * useful, clearly factual pieces of information about the user (US-6.2).
 * Proposals become `hypothesis` memories; sensitive data must never be listed.
 */
class MemoryHypothesisExtractor implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'Você analisa a última troca de uma conversa entre um usuário e o assistente JARVIS. '
            .'Identifique até 3 informações claras e factuais sobre o usuário que valham a pena lembrar em conversas futuras '
            .'(preferências, projetos, contexto). Extraia apenas o que foi afirmado explicitamente. '
            .'Nunca inclua dados sensíveis: documentos, CPF, cartões, senhas, chaves de API, dados financeiros ou de saúde. '
            .'Se não houver nada útil, retorne uma lista vazia.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()
                ->items($schema->object(fn (JsonSchema $schema) => [
                    'content' => $schema->string()->min(1)->max(2000)->required(),
                ]))
                ->max(3)
                ->required(),
        ];
    }
}
