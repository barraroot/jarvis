<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Tool;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Registers the internal `whatsapp.*` tools (CT-30) and authorizes them only
 * for the agents that talk on the user's behalf (RF-14). Safe to run on an
 * existing install: the catalogue sync is idempotent and
 * `syncWithoutDetaching` never removes the links configured in the admin.
 */
class WhatsappToolSeeder extends Seeder
{
    public const string IDENTIFIER_PREFIX = 'whatsapp.';

    /**
     * @var list<string>
     */
    public const array AGENT_SLUGS = ['personal-assistant', 'communication'];

    public function run(): void
    {
        Artisan::call('jarvis:tools:sync-internal');

        $toolIds = Tool::query()
            ->where('identifier', 'like', self::IDENTIFIER_PREFIX.'%')
            ->pluck('id')
            ->all();

        if ($toolIds === []) {
            return;
        }

        Agent::query()
            ->whereIn('slug', self::AGENT_SLUGS)
            ->each(fn (Agent $agent) => $agent->tools()->syncWithoutDetaching($toolIds));
    }
}
