<?php

namespace App\Tools\Internal;

use App\Tools\Contracts\JarvisTool;
use App\Tools\Internal\Concerns\SendsWhatsappMessage;

/**
 * `whatsapp.send.location` (CT-30): a location pin to one recipient (RF-19, RF-27).
 */
class WhatsappSendLocationTool implements JarvisTool
{
    use SendsWhatsappMessage;

    public function identifier(): string
    {
        return 'whatsapp.send.location';
    }

    public function name(): string
    {
        return 'WhatsApp: enviar localização';
    }

    public function description(): string
    {
        return 'Envia uma localização (latitude/longitude e nome opcional) pelo WhatsApp do operador a um único destinatário. '
            .'Exige aprovação; o envio é enfileirado e entregue respeitando os limites anti-banimento.';
    }

    protected function kind(): string
    {
        return 'location';
    }

    protected function contentProperties(): array
    {
        return [
            'latitude' => ['type' => 'number', 'minimum' => -90, 'maximum' => 90, 'description' => 'Latitude em graus decimais.'],
            'longitude' => ['type' => 'number', 'minimum' => -180, 'maximum' => 180, 'description' => 'Longitude em graus decimais.'],
            'name' => ['type' => 'string', 'maxLength' => 256, 'description' => 'Nome opcional do local.'],
        ];
    }

    protected function requiredContent(): array
    {
        return ['latitude', 'longitude'];
    }

    protected function payload(array $input): array
    {
        return [
            'latitude' => $input['latitude'],
            'longitude' => $input['longitude'],
            'name' => $input['name'] ?? null,
        ];
    }
}
