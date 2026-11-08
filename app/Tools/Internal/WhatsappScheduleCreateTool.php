<?php

namespace App\Tools\Internal;

use App\Models\WhatsappConsent;
use App\Models\WhatsappSchedule;
use App\Services\WhatsappContactDirectory;
use App\Services\WhatsappOutboundService;
use App\Services\WhatsappScheduleService;
use App\Support\WhatsappMediaFetcher;
use App\Tools\Contracts\JarvisTool;
use App\Tools\Exceptions\ToolInputInvalidException;
use App\Tools\Exceptions\ToolNotAuthorizedException;
use App\Tools\Internal\Concerns\InteractsWithWhatsapp;
use App\Tools\ToolContext;

/**
 * `whatsapp.schedule.create` (CT-30): a one-off (`run_at`) or recurring
 * (5-field cron, ≥ 1 h apart) send to one recipient, read in
 * `America/Sao_Paulo` (RF-30, C-02). The approval of the creation covers
 * every occurrence for 90 days (C-03); each occurrence re-checks consent,
 * session and brakes when dispatched (RF-31). Media URLs are validated and
 * downloaded now (RF-44) and again at every delivery.
 */
class WhatsappScheduleCreateTool implements JarvisTool
{
    use InteractsWithWhatsapp;

    /**
     * Content fields each kind requires.
     *
     * @var array<string, list<string>>
     */
    public const array REQUIRED_CONTENT = [
        'text' => ['text'],
        'image' => ['url'],
        'video' => ['url'],
        'audio' => ['url'],
        'document' => ['url'],
        'location' => ['latitude', 'longitude'],
    ];

    /**
     * Content fields each kind accepts.
     *
     * @var array<string, list<string>>
     */
    public const array ALLOWED_CONTENT = [
        'text' => ['text'],
        'image' => ['url', 'caption'],
        'video' => ['url', 'caption'],
        'audio' => ['url'],
        'document' => ['url', 'filename'],
        'location' => ['latitude', 'longitude', 'name'],
    ];

    public function identifier(): string
    {
        return 'whatsapp.schedule.create';
    }

    public function name(): string
    {
        return 'WhatsApp: agendar envio';
    }

    public function description(): string
    {
        return 'Agenda um envio pelo WhatsApp do operador a um único destinatário: único ("run_at", ISO 8601, até 90 dias à frente) '
            .'ou recorrente ("recurrence", cron de 5 campos com pelo menos 1 hora entre ocorrências). Datas sem fuso e o cron '
            .'são lidos em America/Sao_Paulo. Conteúdo conforme "kind": text → "text"; image/video → "url" (+ "caption"); '
            .'audio → "url"; document → "url" (+ "filename"); location → "latitude"/"longitude" (+ "name"). '
            .'Exige aprovação, válida para as ocorrências por até 90 dias.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'to' => WhatsappSendTextTool::recipientSchema(),
                'kind' => ['type' => 'string', 'enum' => WhatsappOutboundService::MESSAGE_KINDS, 'description' => 'Tipo do envio.'],
                'run_at' => ['type' => 'string', 'maxLength' => 40, 'description' => 'Data/hora do envio único (ISO 8601).'],
                'recurrence' => ['type' => 'string', 'maxLength' => 64, 'description' => 'Expressão cron de 5 campos (ex.: "0 9 * * 1-5").'],
                'text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => WhatsappSendTextTool::MAX_TEXT_LENGTH, 'description' => 'Texto (kind text).'],
                'url' => ['type' => 'string', 'format' => 'uri', 'minLength' => 1, 'maxLength' => WhatsappSendImageTool::MAX_URL_LENGTH, 'description' => 'URL https pública da mídia.'],
                'caption' => ['type' => 'string', 'maxLength' => WhatsappSendImageTool::MAX_CAPTION_LENGTH, 'description' => 'Legenda (image/video).'],
                'filename' => ['type' => 'string', 'minLength' => 1, 'maxLength' => WhatsappSendImageTool::MAX_FILENAME_LENGTH, 'description' => 'Nome do arquivo (document).'],
                'latitude' => ['type' => 'number', 'minimum' => -90, 'maximum' => 90, 'description' => 'Latitude (location).'],
                'longitude' => ['type' => 'number', 'minimum' => -180, 'maximum' => 180, 'description' => 'Longitude (location).'],
                'name' => ['type' => 'string', 'maxLength' => 256, 'description' => 'Nome do local (location).'],
            ],
            'required' => ['to', 'kind'],
        ];
    }

    public function outputSchema(): array
    {
        return WhatsappScheduleListTool::scheduleSchema();
    }

    /**
     * `envio`, plus `midia` for media kinds (CT-30).
     */
    public function requiredScopes(array $input): array
    {
        return in_array($input['kind'] ?? null, WhatsappSchedule::MEDIA_KINDS, true)
            ? [WhatsappConsent::SCOPE_SEND, WhatsappConsent::SCOPE_MEDIA]
            : [WhatsappConsent::SCOPE_SEND];
    }

    public function callsUpstream(): bool
    {
        return false;
    }

    public function writesToWhatsapp(): bool
    {
        return true;
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function handle(array $input, ToolContext $context): array
    {
        $schedules = app(WhatsappScheduleService::class);

        $errors = [
            ...$schedules->definitionErrors($input['run_at'] ?? null, $input['recurrence'] ?? null, now()),
            ...$this->contentErrors($input),
        ];

        if ($errors !== []) {
            throw new ToolInputInvalidException($this->identifier(), $errors);
        }

        $session = $this->guardInvocation($input, $context);

        if ($context->approval === null) {
            throw new ToolNotAuthorizedException("Tool [{$this->identifier()}] requires an approval.");
        }

        if (in_array($input['kind'], WhatsappMediaFetcher::KINDS, true)) {
            app(WhatsappMediaFetcher::class)->fetch($input['url'], $input['kind']);
        }

        $schedule = $schedules->create($context->user, $session, $context->approval, [
            'to_jid' => WhatsappContactDirectory::normalizeJid($input['to']),
            'kind' => $input['kind'],
            'payload' => array_intersect_key($input, array_flip(self::ALLOWED_CONTENT[$input['kind']])),
            'run_at' => $input['run_at'] ?? null,
            'recurrence' => $input['recurrence'] ?? null,
        ]);

        return WhatsappScheduleListTool::present($schedule);
    }

    /**
     * Content fields missing for the kind, or belonging to another kind.
     *
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function contentErrors(array $input): array
    {
        $kind = $input['kind'];
        $errors = [];

        foreach (self::REQUIRED_CONTENT[$kind] as $field) {
            if (! isset($input[$field])) {
                $errors[] = "{$field} is required for kind {$kind}";
            }
        }

        $allContent = array_unique(array_merge(...array_values(self::ALLOWED_CONTENT)));

        foreach (array_diff($allContent, self::ALLOWED_CONTENT[$kind]) as $field) {
            if (isset($input[$field])) {
                $errors[] = "{$field} is not allowed for kind {$kind}";
            }
        }

        return $errors;
    }
}
