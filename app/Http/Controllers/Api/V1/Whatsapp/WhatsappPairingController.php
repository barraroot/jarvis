<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Exceptions\WhatsappSessionStateException;
use App\Exceptions\WhatsappUpstreamUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Whatsapp\PairWhatsappPhoneRequest;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\WhatsappSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Pairing of the owner's session (RF-05): CT-05 starts the connection and
 * returns the QR, CT-06 returns the linking code for a phone. Only for a
 * `pending`/`disconnected` session that is not suspended (else 409). Answers
 * carry `Cache-Control: no-store`; nothing is persisted or logged (RNF-10).
 */
class WhatsappPairingController extends Controller
{
    public function __construct(private readonly WhatsappSessionService $sessions) {}

    public function qr(Request $request): JsonResponse
    {
        $session = $this->ownedSession($request);

        try {
            $qr = $this->sessions->requestQr($session);
        } catch (WhatsappSessionStateException $exception) {
            abort(409, $exception->getMessage());
        } catch (WhatsappUpstreamUnavailableException $exception) {
            abort(502, $exception->getMessage());
        }

        return $this->noStore(['qr' => $qr]);
    }

    public function pairPhone(PairWhatsappPhoneRequest $request): JsonResponse
    {
        $session = $this->ownedSession($request);

        try {
            $code = $this->sessions->pairPhone($session, $request->string('phone')->toString());
        } catch (WhatsappSessionStateException $exception) {
            abort(409, $exception->getMessage());
        } catch (WhatsappUpstreamUnavailableException $exception) {
            abort(502, $exception->getMessage());
        }

        return $this->noStore(['code' => $code]);
    }

    private function ownedSession(Request $request): WhatsappSession
    {
        /** @var User $user */
        $user = $request->user();

        $session = WhatsappSession::query()->with('status')->where('user_id', $user->id)->first();

        if ($session === null) {
            abort(404, __('whatsapp.session.not_found'));
        }

        Gate::authorize('update', $session);

        return $session;
    }

    /**
     * @param  array<string, string>  $data
     */
    private function noStore(array $data): JsonResponse
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }
}
