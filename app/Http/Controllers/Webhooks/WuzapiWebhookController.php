<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWuzapiEvent;
use App\Models\WhatsappSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CT-09 receiver (RF-35, RNF-06): finds the session by its `webhook_key`
 * (404), checks the HMAC-SHA256 of the raw body against `x-hmac-signature`
 * (401) and queues the body for `ProcessWuzapiEvent` (202). No HTTP or AI
 * call and no persistence happen in the request cycle.
 */
class WuzapiWebhookController extends Controller
{
    public const string SIGNATURE_HEADER = 'x-hmac-signature';

    public function __invoke(Request $request, string $webhookKey): JsonResponse
    {
        $session = WhatsappSession::query()->forWebhookKey($webhookKey)->first();

        abort_if($session === null, 404);

        $body = $request->getContent();
        $signature = $request->header(self::SIGNATURE_HEADER);

        abort_unless(
            is_string($signature) && $signature !== '' && hash_equals(hash_hmac('sha256', $body, (string) $session->hmac_key), strtolower($signature)),
            401,
        );

        ProcessWuzapiEvent::dispatch($session->id, $body)->onConnection('redis');

        return response()->json(['data' => ['accepted' => true]], 202);
    }
}
