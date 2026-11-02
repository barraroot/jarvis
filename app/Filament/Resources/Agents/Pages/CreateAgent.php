<?php

namespace App\Filament\Resources\Agents\Pages;

use App\Filament\Resources\Agents\AgentResource;
use App\Models\Agent;
use App\Models\AuditAction;
use App\Services\DelegationService;
use App\Support\AuditLogger;
use Filament\Resources\Pages\CreateRecord;

class CreateAgent extends CreateRecord
{
    protected static string $resource = AgentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->promoteAsDefault($data);
    }

    protected function afterCreate(): void
    {
        /** @var Agent $agent */
        $agent = $this->getRecord();

        app(AuditLogger::class)->log(AuditAction::AGENT_CREATED, auth()->user(), $agent, [
            'slug' => $agent->slug,
            'name' => $agent->name,
            'max_delegation_depth' => $agent->max_delegation_depth,
            'max_tool_calls' => $agent->max_tool_calls,
            'timeout_seconds' => $agent->timeout_seconds,
            'is_default' => $agent->is_default,
            'is_active' => $agent->is_active,
        ]);

        // The new agent's `delegate.<slug>` tool row exists immediately.
        app(DelegationService::class)->syncTools();
    }

    /**
     * Only one agent may be default (partial unique index): promoting the new
     * agent clears the current default first.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function promoteAsDefault(array $data): array
    {
        if ($data['is_default'] ?? false) {
            Agent::query()->where('is_default', true)->update(['is_default' => false]);
        }

        return $data;
    }
}
