<?php

namespace App\Tools\Internal;

use App\Tools\Contracts\JarvisTool;
use App\Tools\Internal\Concerns\InteractsWithWhatsapp;
use App\Tools\ToolContext;

/**
 * `whatsapp.session.status` (CT-30): the caller's own session status, read
 * locally — it works while the session is not `connected`.
 */
class WhatsappSessionStatusTool implements JarvisTool
{
    use InteractsWithWhatsapp;

    public function identifier(): string
    {
        return 'whatsapp.session.status';
    }

    public function name(): string
    {
        return 'WhatsApp: status da sessão';
    }

    public function description(): string
    {
        return 'Retorna o status da sessão do WhatsApp do operador (pending, connected, disconnected ou error), '
            .'o telefone mascarado e os escopos de consentimento aceitos.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [],
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['pending', 'connected', 'disconnected', 'error']],
                'phone_masked' => ['type' => ['string', 'null']],
                'connected_at' => ['type' => ['string', 'null']],
                'last_checked_at' => ['type' => ['string', 'null']],
                'scopes' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['status', 'phone_masked', 'connected_at', 'last_checked_at', 'scopes'],
        ];
    }

    public function requiredScopes(array $input): array
    {
        return [];
    }

    public function callsUpstream(): bool
    {
        return false;
    }

    public function writesToWhatsapp(): bool
    {
        return false;
    }

    public function handle(array $input, ToolContext $context): array
    {
        $session = $this->guardInvocation($input, $context);
        $context->user->loadMissing('whatsappConsent');

        return $this->untrusted([
            'status' => (string) $session->statusSlug(),
            'phone_masked' => $session->phone_masked,
            'connected_at' => $session->connected_at?->toIso8601String(),
            'last_checked_at' => $session->last_checked_at?->toIso8601String(),
            'scopes' => array_values($context->user->whatsappConsent->scopes ?? []),
        ]);
    }
}
