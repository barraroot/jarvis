<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\AuditAction;
use App\Models\User;
use App\Support\AuditLogger;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->hidden(fn (User $record): bool => UserResource::isLastActiveAdmin($record)),
            RestoreAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        /** @var User $user */
        $user = $this->getRecord();

        $changes = Arr::except($user->getChanges(), ['updated_at']);

        if ($changes === []) {
            return;
        }

        $wasDeactivated = array_key_exists('is_active', $changes) && ! $user->is_active;

        app(AuditLogger::class)->log(
            $wasDeactivated ? AuditAction::USER_DEACTIVATED : AuditAction::USER_UPDATED,
            auth()->user(),
            $user,
            [
                'changed' => array_keys($changes),
                'changes' => Arr::except($changes, ['password', 'remember_token']),
            ],
        );
    }
}
