<?php

namespace App\Services;

use App\Events\Connector\StatusChanged;
use App\Models\ConnectorStatus;
use App\Models\McpServer;
use App\Models\NotificationType;

/**
 * Records a connector check outcome (US-9.4): status, `last_checked_at` and
 * `last_error`. A transition broadcasts `connector.status.changed`; entering
 * `error` also notifies the connector audience (`connector_error`, US-10.4).
 */
class ConnectorStatusService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * @return bool Whether the status changed.
     */
    public function record(McpServer $server, string $statusSlug, ?string $error = null): bool
    {
        $previous = $server->status?->slug;

        $server->forceFill([
            'connector_status_id' => ConnectorStatus::idFor($statusSlug),
            'last_checked_at' => now(),
            'last_error' => $statusSlug === ConnectorStatus::ERROR ? $error : null,
        ])->save();

        $server->load('status');

        if ($previous === $statusSlug) {
            return false;
        }

        StatusChanged::dispatch($server, $previous);

        if ($statusSlug === ConnectorStatus::ERROR) {
            foreach ($server->audience() as $user) {
                $this->notifications->notify(
                    $user,
                    NotificationType::CONNECTOR_ERROR,
                    "Conector {$server->name} com erro",
                    $error,
                    ['mcp_server_id' => $server->id],
                );
            }
        }

        return true;
    }
}
