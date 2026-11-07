<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Whatsapp\StoreWhatsappConsentRequest;
use App\Http\Resources\WhatsappConsentResource;
use App\Models\User;
use App\Services\WhatsappConsentService;
use App\Services\WhatsappSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CT-01 accepts the current consent text with a scope subset (RF-09, RF-13);
 * CT-02 revokes it with the RF-11 effects. Revoking is idempotent and always
 * answers the same body.
 */
class WhatsappConsentController extends Controller
{
    public function __construct(
        private readonly WhatsappConsentService $consents,
        private readonly WhatsappSessionService $sessions,
    ) {}

    public function store(StoreWhatsappConsentRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var list<string> $scopes */
        $scopes = $request->validated('scopes');

        $consent = $this->consents->grant($user, $request->integer('version'), $scopes);

        return (new WhatsappConsentResource($consent))
            ->response()
            ->setStatusCode(200);
    }

    public function destroy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->sessions->revokeConsent($user);

        return response()->json(['data' => ['revoked' => true]]);
    }
}
