<?php

namespace App\Console\Commands;

use App\Exceptions\WuzapiRequestException;
use App\Models\ConnectorStatus;
use App\Models\WhatsappConsent;
use App\Models\WhatsappSession;
use App\Services\WhatsappStatusRecorder;
use App\Services\WuzapiClient;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Periodic WhatsApp session check (RF-07): asks wuzapi `/session/status` for
 * every provisioned, non-suspended session whose owner holds a valid consent
 * and whose last check is older than `Settings::connectorHealthCheckMinutes()`,
 * then reconciles the status (RF-06, D15). Scheduled every minute so a
 * changed interval applies without a deploy.
 */
class WhatsappHealthCheck extends Command
{
    protected $signature = 'jarvis:whatsapp:health-check {--force : Check every eligible session regardless of the interval}';

    protected $description = 'Check WhatsApp sessions on wuzapi and record their status';

    public function handle(WuzapiClient $wuzapi, WhatsappStatusRecorder $recorder, Settings $settings): int
    {
        $dueBefore = now()->subMinutes($settings->connectorHealthCheckMinutes())->addSeconds(30);

        $sessions = WhatsappSession::query()
            ->whereNull('suspended_at')
            ->whereNotNull('wuzapi_user_id')
            ->whereHas('user.whatsappConsent', fn (Builder $query) => $query
                ->where('version', WhatsappConsent::CURRENT_VERSION)
                ->whereNull('revoked_at'))
            ->with(['status', 'user'])
            ->unless($this->option('force'), fn (Builder $query) => $query->where(function (Builder $query) use ($dueBefore): void {
                $query->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', $dueBefore);
            }))
            ->get();

        $changed = 0;

        foreach ($sessions as $session) {
            try {
                $status = $wuzapi->forSession($session)->status();

                $transitioned = $recorder->record($session, $this->statusFor($session, $status), checked: true);
            } catch (WuzapiRequestException $exception) {
                $transitioned = $recorder->record($session, ConnectorStatus::ERROR, $exception->upstreamDetail, checked: true);
            }

            $changed += (int) $transitioned;
        }

        $this->info("Checked {$sessions->count()} WhatsApp sessions, {$changed} changed status.");

        return self::SUCCESS;
    }

    /**
     * D15: connected and logged in → `connected`; never paired and not logged
     * in → stays `pending`; anything else → `disconnected`.
     *
     * @param  array{connected: bool, logged_in: bool}  $status
     */
    private function statusFor(WhatsappSession $session, array $status): string
    {
        if ($status['connected'] && $status['logged_in']) {
            return ConnectorStatus::CONNECTED;
        }

        if (! $status['logged_in'] && $session->connected_at === null) {
            return ConnectorStatus::PENDING;
        }

        return ConnectorStatus::DISCONNECTED;
    }
}
