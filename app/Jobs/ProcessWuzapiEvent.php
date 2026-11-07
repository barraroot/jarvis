<?php

namespace App\Jobs;

use App\Models\WhatsappSession;
use App\Services\WhatsappInboundService;
use App\Services\WhatsappStatusRecorder;
use App\Services\WuzapiEventParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Processes one verified webhook body off the request cycle (RNF-06):
 * connection events reconcile the session status (RF-39, D15) and `Message`
 * events are stored as untrusted data (RF-36). It never dispatches an agent,
 * task, tool or send (RF-37), and never logs the raw body.
 */
class ProcessWuzapiEvent implements ShouldQueue
{
    use Queueable;

    public const string QUEUE = 'default';

    public int $tries = 1;

    public function __construct(
        public readonly int $whatsappSessionId,
        public readonly string $body,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(WuzapiEventParser $parser, WhatsappStatusRecorder $recorder, WhatsappInboundService $inbound): void
    {
        $session = WhatsappSession::query()->with('status')->find($this->whatsappSessionId);

        if ($session === null) {
            return;
        }

        $event = $parser->parse($this->body);

        if ($event === null) {
            Log::warning('Ignored a malformed wuzapi webhook body.', ['whatsapp_session_id' => $session->id]);

            return;
        }

        $status = $parser->connectionStatus($event['type']);

        if ($status !== null) {
            $recorder->record($session, $status);

            return;
        }

        if ($event['type'] !== WuzapiEventParser::MESSAGE) {
            return;
        }

        $message = $parser->message($event['event']);

        if ($message !== null) {
            $inbound->store($session, $message);
        }
    }
}
