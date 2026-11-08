<?php

namespace App\Http\Resources;

use App\Models\WhatsappSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * CT-08 schedule (UI-05). Expects `status` eager loaded. Times are UTC
 * instants; `timezone` tells the SPA how to display them and how the cron
 * expression is read (C-02). The payload is not exposed.
 *
 * @mixin WhatsappSchedule
 */
class WhatsappScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'to' => $this->to_jid,
            'kind' => $this->kind,
            'recurrence' => $this->recurrence,
            'timezone' => WhatsappSchedule::TIMEZONE,
            'next_run_at' => $this->next_run_at?->toIso8601String(),
            'approval_expires_at' => $this->approval_expires_at->toIso8601String(),
            'status' => $this->statusSlug(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
