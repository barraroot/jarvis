<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Classifies a user message into one of the four routing intents (US-3.3):
 * a direct answer, a tool use, a delegation to a specialized agent or a long
 * background task, suggesting the best agent for the job and how confident
 * the classification is. The router falls back to keyword heuristics when
 * this call fails, so a provider outage never blocks the conversation.
 */
class IntentClassifier implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'Você classifica mensagens de um usuário para o assistente JARVIS. '
            .'Escolha exatamente uma destas intenções: '
            .'"direct_answer" — pergunta simples ou conversa que o assistente responde diretamente; '
            .'"use_tool" — o pedido exige uma ferramenta autorizada (ex.: lembrar algo, consultar hora/data); '
            .'"delegate_agent" — o pedido exige um agente especializado (research, developer, automation, knowledge, productivity, communication); '
            .'"long_task" — trabalho longo em segundo plano (pesquisa aprofundada, relatório, auditoria, levantamento extenso). '
            .'Responda com a intenção, o slug do agente mais adequado (ou null para direct_answer) e uma confiança entre 0 e 1. '
            .'Em caso de dúvida, prefira "direct_answer".';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'intent' => $schema->string()->required(),
            'agent_slug' => $schema->string(),
            'confidence' => $schema->number(),
        ];
    }
}
