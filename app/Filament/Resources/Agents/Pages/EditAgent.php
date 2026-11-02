<?php

namespace App\Filament\Resources\Agents\Pages;

use App\Filament\Resources\Agents\AgentResource;
use App\Models\Agent;
use App\Models\AuditAction;
use App\Services\DelegationService;
use App\Support\AuditLogger;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class EditAgent extends EditRecord
{
    protected static string $resource = AgentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->after(function (Agent $record): void {
                    app(AuditLogger::class)->log(AuditAction::AGENT_DELETED, auth()->user(), $record, [
                        'slug' => $record->slug,
                        'name' => $record->name,
                    ]);
                }),
            RestoreAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->promoteAsDefault($data);
    }

    protected function afterValidate(): void
    {
        /** @var Agent $record */
        $record = $this->getRecord();

        // The default agent cannot be deactivated directly: another agent must
        // be promoted to default first (US-7.2).
        if ($record->is_default && ! ($this->data['is_active'] ?? true)) {
            throw ValidationException::withMessages([
                'data.is_active' => 'O agente padrão não pode ser desativado. Promova outro agente a padrão antes de desativá-lo.',
            ]);
        }
    }

    protected function afterSave(): void
    {
        /** @var Agent $agent */
        $agent = $this->getRecord();

        $changes = Arr::except($agent->getChanges(), ['updated_at']);

        if ($changes !== []) {
            app(AuditLogger::class)->log(AuditAction::AGENT_UPDATED, auth()->user(), $agent, [
                'slug' => $agent->slug,
                'name' => $agent->name,
                'changed' => array_keys($changes),
                'changes' => $changes,
            ]);
        }

        // Keeps `delegate.<slug>` rows in step with activation and limits.
        app(DelegationService::class)->syncTools();
    }

    /**
     * Only one agent may be default (partial unique index): promoting this
     * agent clears the current default first.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function promoteAsDefault(array $data): array
    {
        if ($data['is_default'] ?? false) {
            Agent::query()->whereKeyNot($this->getRecord()->getKey())->where('is_default', true)
                ->update(['is_default' => false]);
        }

        return $data;
    }
}
