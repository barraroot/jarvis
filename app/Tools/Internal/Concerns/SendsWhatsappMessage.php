<?php

namespace App\Tools\Internal\Concerns;

use App\Exceptions\WhatsappOperationException;
use App\Services\WhatsappContactDirectory;
use App\Services\WhatsappOutboundService;
use App\Tools\ToolContext;

/**
 * Shared shape of the `whatsapp.send.*` tools (CT-30): exactly one
 * recipient (RF-27 — a list fails the input schema), approval required
 * (`is_write`, RF-15) and a single path out through
 * `WhatsappOutboundService::enqueue()`, which applies the brakes and the
 * media checks (RF-19, RF-44) and answers `{outbound_id, status}`.
 */
trait SendsWhatsappMessage
{
    use InteractsWithWhatsapp;

    /**
     * One recipient: a phone number (8–15 digits, optional `+`), a user JID
     * or a group JID.
     */
    public const string RECIPIENT_PATTERN = '^(\+?[0-9]{8,15}|[0-9]{8,20}@s\.whatsapp\.net|[0-9]+(-[0-9]+)?@g\.us)$';

    /**
     * Outbound kind (one of `WhatsappOutboundService::MESSAGE_KINDS`).
     */
    abstract protected function kind(): string;

    /**
     * Schema properties of the content, besides `to`.
     *
     * @return array<string, array<string, mixed>>
     */
    abstract protected function contentProperties(): array;

    /**
     * Required content properties, besides `to`.
     *
     * @return list<string>
     */
    abstract protected function requiredContent(): array;

    /**
     * Outbound payload built from the validated input.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    abstract protected function payload(array $input): array;

    /**
     * JSON Schema of the `to` property, shared with `whatsapp.schedule.create`.
     *
     * @return array<string, mixed>
     */
    public static function recipientSchema(): array
    {
        return [
            'type' => 'string',
            'pattern' => self::RECIPIENT_PATTERN,
            'maxLength' => 128,
            'description' => 'Um único destinatário: telefone com DDI (ex.: 5511999999999) ou JID (ex.: 5511999999999@s.whatsapp.net).',
        ];
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => ['to' => self::recipientSchema()] + $this->contentProperties(),
            'required' => ['to', ...$this->requiredContent()],
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'outbound_id' => ['type' => 'integer'],
                'status' => ['type' => 'string'],
            ],
            'required' => ['outbound_id', 'status'],
        ];
    }

    public function requiredScopes(array $input): array
    {
        return WhatsappOutboundService::scopesFor($this->kind());
    }

    public function callsUpstream(): bool
    {
        return true;
    }

    public function writesToWhatsapp(): bool
    {
        return true;
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function isSensitive(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{outbound_id: int, status: string}
     *
     * @throws WhatsappOperationException
     */
    public function handle(array $input, ToolContext $context): array
    {
        $this->guardInvocation($input, $context);

        return app(WhatsappOutboundService::class)->enqueue(
            $context->user,
            $this->kind(),
            WhatsappContactDirectory::normalizeJid((string) $input['to']),
            array_filter($this->payload($input), fn (mixed $value): bool => $value !== null),
            $context->approval,
        );
    }
}
