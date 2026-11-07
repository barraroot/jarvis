<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Exceptions\WhatsappOperationException;
use App\Exceptions\WhatsappUpstreamUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Resources\WhatsappSessionResource;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\WhatsappSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The authenticated user's WhatsApp session: CT-03 shows it (or null), CT-04
 * provisions it (201) or returns the existing one (200), CT-07 removes it.
 * No consent → 422 on `consent`; wuzapi failure → 502; no session → 404.
 */
class WhatsappSessionController extends Controller
{
    public function __construct(private readonly WhatsappSessionService $sessions) {}

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $session = WhatsappSession::query()
            ->with(['status', 'user.whatsappConsent'])
            ->where('user_id', $user->id)
            ->first();

        if ($session === null) {
            return response()->json(['data' => null]);
        }

        Gate::authorize('view', $session);

        return (new WhatsappSessionResource($session))->response();
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        Gate::authorize('create', WhatsappSession::class);

        try {
            [$session, $created] = $this->sessions->provision($user);
        } catch (WhatsappOperationException) {
            throw ValidationException::withMessages(['consent' => __('whatsapp.consent.required')]);
        } catch (WhatsappUpstreamUnavailableException $exception) {
            abort(502, $exception->getMessage());
        }

        $session->loadMissing(['status', 'user.whatsappConsent']);

        return (new WhatsappSessionResource($session))
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    public function destroy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $session = WhatsappSession::query()->where('user_id', $user->id)->first();

        if ($session === null) {
            abort(404, __('whatsapp.session.not_found'));
        }

        Gate::authorize('delete', $session);

        try {
            $this->sessions->remove($user);
        } catch (WhatsappUpstreamUnavailableException $exception) {
            abort(502, $exception->getMessage());
        }

        return response()->json(['data' => ['deleted' => true]]);
    }
}
