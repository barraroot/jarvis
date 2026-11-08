<?php

namespace App\Tools\Internal;

use App\Tools\Contracts\JarvisTool;
use App\Tools\Internal\Concerns\SendsWhatsappMessage;

/**
 * `whatsapp.send.text` (CT-30): text message to one recipient (RF-19, RF-27).
 */
class WhatsappSendTextTool implements JarvisTool
{
    use SendsWhatsappMessage;

    public const int MAX_TEXT_LENGTH = 4096;

    public function identifier(): string
    {
        return 'whatsapp.send.text';
    }

    public function name(): string
    {
        return 'WhatsApp: enviar texto';
    }

    public function description(): string
    {
        return 'Envia uma mensagem de texto pelo WhatsApp do operador a um único destinatário. '
            .'Exige aprovação; o envio é enfileirado e entregue respeitando os limites anti-banimento.';
    }

    protected function kind(): string
    {
        return 'text';
    }

    protected function contentProperties(): array
    {
        return [
            'text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_TEXT_LENGTH, 'description' => 'Texto da mensagem (até 4096 caracteres).'],
        ];
    }

    protected function requiredContent(): array
    {
        return ['text'];
    }

    protected function payload(array $input): array
    {
        return ['text' => $input['text']];
    }
}
