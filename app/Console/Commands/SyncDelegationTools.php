<?php

namespace App\Console\Commands;

use App\Services\DelegationService;
use Illuminate\Console\Command;

/**
 * Registers or refreshes the `delegate.<slug>` catalogue rows for every
 * active agent (US-7.3). Idempotent: repeated runs converge to the same
 * rows, and rows for missing/inactive agents are deactivated.
 */
class SyncDelegationTools extends Command
{
    protected $signature = 'jarvis:agents:sync-delegation';

    protected $description = 'Register delegate tools for active agents in the tools catalogue';

    public function handle(DelegationService $delegation): int
    {
        $delegation->syncTools();

        $this->info('Delegate tools synced.');

        return self::SUCCESS;
    }
}
