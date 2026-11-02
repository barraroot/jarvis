<?php

namespace App\Filament\Resources\AiProviders\Pages;

use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Models\AiProvider;
use App\Models\AuditAction;
use App\Support\AuditLogger;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;

class EditAiProvider extends EditRecord
{
    protected static string $resource = AiProviderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->after(function (AiProvider $record): void {
                    app(AuditLogger::class)->log(AuditAction::PROVIDER_DELETED, auth()->user(), $record, [
                        'name' => $record->name,
                        'slug' => $record->slug,
                    ]);
                }),
            RestoreAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        /** @var AiProvider $provider */
        $provider = $this->getRecord();

        $changes = Arr::except($provider->getChanges(), ['updated_at']);

        if ($changes === []) {
            return;
        }

        app(AuditLogger::class)->log(AuditAction::PROVIDER_UPDATED, auth()->user(), $provider, [
            'changed' => array_keys($changes),
            'changes' => Arr::except($changes, ['api_key']),
        ]);
    }
}
