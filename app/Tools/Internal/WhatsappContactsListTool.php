<?php

namespace App\Tools\Internal;

use App\Models\WhatsappConsent;
use App\Services\WuzapiClient;
use App\Tools\Contracts\JarvisTool;
use App\Tools\Internal\Concerns\InteractsWithWhatsapp;
use App\Tools\ToolContext;

/**
 * `whatsapp.contacts.list` (CT-30): the caller's WhatsApp address book
 * (`GET /user/contacts`), optionally filtered by name or number.
 */
class WhatsappContactsListTool implements JarvisTool
{
    use InteractsWithWhatsapp;

    public const int DEFAULT_LIMIT = 100;

    public const int MAX_LIMIT = 500;

    public function identifier(): string
    {
        return 'whatsapp.contacts.list';
    }

    public function name(): string
    {
        return 'WhatsApp: listar contatos';
    }

    public function description(): string
    {
        return 'Lista os contatos do WhatsApp do operador (JID e nomes), com filtro opcional por nome ou número.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'description' => 'Trecho do nome ou do número.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'description' => 'Máximo de contatos (padrão 100).'],
            ],
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'contacts' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'jid' => ['type' => 'string'],
                            'name' => ['type' => ['string', 'null']],
                            'push_name' => ['type' => ['string', 'null']],
                            'business_name' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['jid', 'name', 'push_name', 'business_name'],
                    ],
                ],
                'total' => ['type' => 'integer'],
            ],
            'required' => ['contacts', 'total'],
        ];
    }

    public function requiredScopes(array $input): array
    {
        return [WhatsappConsent::SCOPE_READ];
    }

    public function callsUpstream(): bool
    {
        return true;
    }

    public function writesToWhatsapp(): bool
    {
        return false;
    }

    public function handle(array $input, ToolContext $context): array
    {
        $session = $this->guardInvocation($input, $context);

        $book = $this->callWuzapi($session, fn (WuzapiClient $client): array => $client->contacts());

        $contacts = collect($book)
            ->filter(fn (mixed $contact): bool => is_array($contact))
            ->map(fn (array $contact, string|int $jid): array => [
                'jid' => (string) $jid,
                'name' => self::stringOrNull($contact['FullName'] ?? $contact['FirstName'] ?? null),
                'push_name' => self::stringOrNull($contact['PushName'] ?? null),
                'business_name' => self::stringOrNull($contact['BusinessName'] ?? null),
            ])
            ->values();

        if (filled($input['query'] ?? null)) {
            $needle = mb_strtolower((string) $input['query']);

            $contacts = $contacts->filter(fn (array $contact): bool => str_contains(
                mb_strtolower(implode(' ', array_filter($contact))),
                $needle,
            ))->values();
        }

        return $this->untrusted([
            'contacts' => $contacts->sortBy('jid')->take((int) ($input['limit'] ?? self::DEFAULT_LIMIT))->values()->all(),
            'total' => $contacts->count(),
        ]);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
