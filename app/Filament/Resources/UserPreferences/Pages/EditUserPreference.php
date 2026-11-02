<?php

namespace App\Filament\Resources\UserPreferences\Pages;

use App\Filament\Resources\UserPreferences\UserPreferenceResource;
use App\Models\AuditAction;
use App\Models\UserPreference;
use App\Support\AuditLogger;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;

class EditUserPreference extends EditRecord
{
    protected static string $resource = UserPreferenceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UserPreferenceResource::resetWakeWordConsentAction()
                ->after(fn () => $this->refreshFormData(['wake_word_enabled'])),
        ];
    }

    protected function afterSave(): void
    {
        /** @var UserPreference $preference */
        $preference = $this->getRecord();

        $changes = Arr::except($preference->getChanges(), ['updated_at']);

        if ($changes === []) {
            return;
        }

        app(AuditLogger::class)->log(AuditAction::PREFERENCE_UPDATED, auth()->user(), $preference, [
            'owner_user_id' => $preference->user_id,
            'changed' => array_keys($changes),
            'changes' => $changes,
        ]);
    }
}
