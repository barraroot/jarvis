<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Tool;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Registers the internal `visualization.update_section` tool (RF-16a) and
 * authorizes it for every agent. Safe to run on an existing install: the
 * catalogue sync is idempotent and `syncWithoutDetaching` never removes the
 * links the operator configured in the admin.
 */
class VisualizationToolSeeder extends Seeder
{
    public const string TOOL_IDENTIFIER = 'visualization.update_section';

    public function run(): void
    {
        Artisan::call('jarvis:tools:sync-internal');

        $toolId = Tool::query()->where('identifier', self::TOOL_IDENTIFIER)->value('id');

        if ($toolId === null) {
            return;
        }

        Agent::query()->each(fn (Agent $agent) => $agent->tools()->syncWithoutDetaching([$toolId]));
    }
}
