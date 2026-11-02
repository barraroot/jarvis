<?php

namespace App\Console\Commands;

use App\Exceptions\InvalidMcpUrlException;
use App\Exceptions\McpConnectionException;
use App\Models\ConnectorStatus;
use App\Models\McpServer;
use App\Services\ConnectorStatusService;
use App\Services\McpClient;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Periodic connector health check (US-9.4): pings every active MCP server
 * whose last check is older than `Settings::connectorHealthCheckMinutes()`
 * and records the outcome. Status transitions broadcast
 * `connector.status.changed`; entering `error` notifies (`connector_error`).
 * Scheduled every minute so a changed interval applies without a deploy.
 */
class ConnectorHealthCheck extends Command
{
    protected $signature = 'jarvis:connectors:health-check {--force : Check every active connector regardless of the interval}';

    protected $description = 'Ping active MCP connectors and record their status';

    public function handle(ConnectorStatusService $statuses, Settings $settings): int
    {
        $dueBefore = now()->subMinutes($settings->connectorHealthCheckMinutes())->addSeconds(30);

        $servers = McpServer::query()
            ->active()
            ->with('status')
            ->unless($this->option('force'), fn (Builder $query) => $query->where(function (Builder $query) use ($dueBefore): void {
                $query->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', $dueBefore);
            }))
            ->get();

        $changed = 0;

        foreach ($servers as $server) {
            $client = null;

            try {
                $client = McpClient::for($server);
                $client->ping();

                $transitioned = $statuses->record($server, ConnectorStatus::CONNECTED);
            } catch (McpConnectionException|InvalidMcpUrlException $exception) {
                $transitioned = $statuses->record($server, ConnectorStatus::ERROR, $exception->getMessage());
            } finally {
                $client?->disconnect();
            }

            $changed += (int) $transitioned;
        }

        $this->info("Checked {$servers->count()} connectors, {$changed} changed status.");

        return self::SUCCESS;
    }
}
