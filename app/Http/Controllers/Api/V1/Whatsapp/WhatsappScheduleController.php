<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Exceptions\WhatsappScheduleStateException;
use App\Http\Controllers\Controller;
use App\Http\Resources\WhatsappScheduleResource;
use App\Models\User;
use App\Models\WhatsappSchedule;
use App\Services\WhatsappScheduleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * CT-08: the authenticated user's WhatsApp schedules (UI-05), cancel (RF-33)
 * and resume (RF-43). Another user's schedule → 403; a status that does not
 * allow the transition → 409.
 */
class WhatsappScheduleController extends Controller
{
    public function __construct(private readonly WhatsappScheduleService $schedules) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        Gate::authorize('viewAny', WhatsappSchedule::class);

        $schedules = WhatsappSchedule::query()
            ->with('status')
            ->where('user_id', $user->id)
            ->orderByRaw('next_run_at asc nulls last')
            ->orderBy('id')
            ->get();

        return WhatsappScheduleResource::collection($schedules);
    }

    public function cancel(Request $request, WhatsappSchedule $schedule): WhatsappScheduleResource
    {
        Gate::authorize('update', $schedule);

        /** @var User $user */
        $user = $request->user();

        try {
            return new WhatsappScheduleResource($this->schedules->cancel($schedule, $user));
        } catch (WhatsappScheduleStateException $exception) {
            abort(409, $exception->getMessage());
        }
    }

    public function resume(WhatsappSchedule $schedule): WhatsappScheduleResource
    {
        Gate::authorize('update', $schedule);

        try {
            return new WhatsappScheduleResource($this->schedules->resume($schedule));
        } catch (WhatsappScheduleStateException $exception) {
            abort(409, $exception->getMessage());
        }
    }
}
