<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pending approvals past their window are closed every minute (US-8.3).
Schedule::command('jarvis:approvals:expire')->everyMinute();

// Expired execution data is pruned daily (Notes & Conventions, US-12.2).
Schedule::command('jarvis:retention:prune')->daily();

// MCP connectors are pinged every `connector.health_check_minutes` (default 5);
// the command itself skips connectors checked within the interval (US-9.4).
Schedule::command('jarvis:connectors:health-check')->everyMinute()->withoutOverlapping();
