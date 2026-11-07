<?php

namespace App\Events\Whatsapp;

use App\Models\ConnectorStatus;
use App\Models\WhatsappSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * `whatsapp.session.status.changed` (CT-10, asyncapi.yaml) on the owner's
 * private channel. The payload has exactly the six contract keys and never
 * carries tokens, keys, QR or pairing codes (RNF-01, RNF-10).
 */
class SessionStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public readonly Carbon $at;

    public function __construct(
        public readonly WhatsappSession $session,
        public readonly ?string $previousStatus,
    ) {
        $this->at = now();
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->session->user_id)];
    }

    public function broadcastAs(): string
    {
        return 'whatsapp.session.status.changed';
    }

    /**
     * @return array{session_id: int, status: string, previous_status: string|null, suspended: bool, error: string|null, at: string}
     */
    public function broadcastWith(): array
    {
        $status = (string) $this->session->statusSlug();

        return [
            'session_id' => $this->session->id,
            'status' => $status,
            'previous_status' => $this->previousStatus,
            'suspended' => $this->session->isSuspended(),
            'error' => $status === ConnectorStatus::ERROR ? $this->session->last_error : null,
            'at' => $this->at->toIso8601String(),
        ];
    }
}
