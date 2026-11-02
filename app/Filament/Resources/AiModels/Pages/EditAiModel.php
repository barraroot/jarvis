<?php

namespace App\Filament\Resources\AiModels\Pages;

use App\Filament\Resources\AiModels\AiModelResource;
use App\Models\AiModel;
use App\Models\AuditAction;
use App\Support\AuditLogger;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class EditAiModel extends EditRecord
{
    protected static string $resource = AiModelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->after(function (AiModel $record): void {
                    app(AuditLogger::class)->log(AuditAction::MODEL_DELETED, auth()->user(), $record, [
                        'name' => $record->name,
                        'model_identifier' => $record->model_identifier,
                    ]);
                }),
            RestoreAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            $this->unsetPreviousDefaults($record, $data);

            return parent::handleRecordUpdate($record, $data);
        });
    }

    protected function afterSave(): void
    {
        /** @var AiModel $model */
        $model = $this->getRecord();

        $changes = Arr::except($model->getChanges(), ['updated_at']);

        if ($changes === []) {
            return;
        }

        app(AuditLogger::class)->log(AuditAction::MODEL_UPDATED, auth()->user(), $model, [
            'changed' => array_keys($changes),
            'changes' => $changes,
        ]);
    }

    /**
     * Clear the previous default before saving, respecting the partial unique indexes.
     *
     * @param  array<string, mixed>  $data
     */
    private function unsetPreviousDefaults(Model $record, array $data): void
    {
        if (($data['is_default_text'] ?? false) === true) {
            AiModel::query()->whereKeyNot($record->getKey())->where('is_default_text', true)->update(['is_default_text' => false]);
        }

        if (($data['is_default_embedding'] ?? false) === true) {
            AiModel::query()->whereKeyNot($record->getKey())->where('is_default_embedding', true)->update(['is_default_embedding' => false]);
        }
    }
}
