<?php

namespace App\Tools\Internal;

use App\Tools\Contracts\JarvisTool;
use App\Tools\Internal\Concerns\SendsWhatsappMedia;

/**
 * `whatsapp.send.audio` (CT-30): an audio fetched by the server from an `https`
 * URL (RF-44) and sent to one recipient (RF-19, RF-27).
 */
class WhatsappSendAudioTool implements JarvisTool
{
    use SendsWhatsappMedia;

    public function identifier(): string
    {
        return 'whatsapp.send.audio';
    }

    public function name(): string
    {
        return 'WhatsApp: enviar áudio';
    }

    public function description(): string
    {
        return 'Envia um áudio pelo WhatsApp do operador a um único destinatário, a partir de uma URL https pública '
            .'baixada pelo servidor. Exige aprovação; o envio é enfileirado e entregue respeitando os limites anti-banimento.';
    }

    protected function kind(): string
    {
        return 'audio';
    }
}
