<?php

namespace App\Filament\Resources\Tools\Pages;

use App\Filament\Resources\Tools\ToolResource;
use App\Models\AuditAction;
use App\Models\Tool;
use App\Support\AuditLogger;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;

class EditTool extends EditRecord
{
    protected static string $resource = ToolResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->after(function (Tool $record): void {
                    app(AuditLogger::class)->log(AuditAction::TOOL_DELETED, auth()->user(), $record, [
                        'identifier' => $record->identifier,
                        'name' => $record->name,
                    ]);
                }),
            RestoreAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        /** @var Tool $tool */
        $tool = $this->getRecord();

        $changes = Arr::except($tool->getChanges(), ['updated_at']);

        if ($changes === []) {
            return;
        }

        app(AuditLogger::class)->log(AuditAction::TOOL_UPDATED, auth()->user(), $tool, [
            'identifier' => $tool->identifier,
            'name' => $tool->name,
            'changed' => array_keys($changes),
            'changes' => Arr::except($changes, ['credential']),
        ]);
    }
}
