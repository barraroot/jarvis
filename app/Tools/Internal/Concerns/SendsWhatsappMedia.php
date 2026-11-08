<?php

namespace App\Tools\Internal\Concerns;

/**
 * Media variant of `SendsWhatsappMessage` (`whatsapp.send.image|video|audio|document`):
 * the content is an `https` URL the server downloads at enqueue and again at
 * delivery (RF-44), plus a caption for image/video and a file name for
 * documents. The URL rules themselves live in `WhatsappMediaFetcher`, so a
 * non-`https` URL fails with `whatsapp_media_invalid`, not a schema error.
 */
trait SendsWhatsappMedia
{
    use SendsWhatsappMessage;

    public const int MAX_URL_LENGTH = 2048;

    public const int MAX_CAPTION_LENGTH = 1024;

    public const int MAX_FILENAME_LENGTH = 255;

    protected function contentProperties(): array
    {
        $properties = [
            'url' => ['type' => 'string', 'format' => 'uri', 'minLength' => 1, 'maxLength' => self::MAX_URL_LENGTH, 'description' => 'URL https pública da mídia; o servidor baixa o arquivo.'],
        ];

        if (in_array($this->kind(), ['image', 'video'], true)) {
            $properties['caption'] = ['type' => 'string', 'maxLength' => self::MAX_CAPTION_LENGTH, 'description' => 'Legenda opcional.'];
        }

        if ($this->kind() === 'document') {
            $properties['filename'] = ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_FILENAME_LENGTH, 'description' => 'Nome do arquivo exibido ao destinatário (ex.: relatorio.pdf).'];
        }

        return $properties;
    }

    protected function requiredContent(): array
    {
        return ['url'];
    }

    protected function payload(array $input): array
    {
        return [
            'url' => $input['url'],
            'caption' => $input['caption'] ?? null,
            'filename' => $input['filename'] ?? null,
        ];
    }
}
