<?php

namespace App\Filament\Resources\AiModels\Pages;

use App\Filament\Resources\AiModels\AiModelResource;
use App\Models\AiModel;
use App\Models\AuditAction;
use App\Support\AuditLogger;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateAiModel extends CreateRecord
{
    protected static string $resource = AiModelResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            $this->unsetPreviousDefaults($data);

            return parent::handleRecordCreation($data);
        });
    }

    protected function afterCreate(): void
    {
        /** @var AiModel $model */
        $model = $this->getRecord();

        app(AuditLogger::class)->log(AuditAction::MODEL_CREATED, auth()->user(), $model, [
            'name' => $model->name,
            'model_identifier' => $model->model_identifier,
            'provider' => $model->provider->slug,
            'is_default_text' => $model->is_default_text,
            'is_default_embedding' => $model->is_default_embedding,
            'is_default_realtime' => $model->is_default_realtime,
            'fallback_order' => $model->fallback_order,
            'is_active' => $model->is_active,
        ]);
    }

    /**
     * Clear the previous default before inserting, respecting the partial unique indexes.
     *
     * @param  array<string, mixed>  $data
     */
    private function unsetPreviousDefaults(array $data): void
    {
        if (($data['is_default_text'] ?? false) === true) {
            AiModel::query()->where('is_default_text', true)->update(['is_default_text' => false]);
        }

        if (($data['is_default_embedding'] ?? false) === true) {
            AiModel::query()->where('is_default_embedding', true)->update(['is_default_embedding' => false]);
        }

        if (($data['is_default_realtime'] ?? false) === true) {
            AiModel::query()->where('is_default_realtime', true)->update(['is_default_realtime' => false]);
        }
    }
}
