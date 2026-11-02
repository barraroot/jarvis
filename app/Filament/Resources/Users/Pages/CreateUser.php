<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\AuditAction;
use App\Support\AuditLogger;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function afterCreate(): void
    {
        app(AuditLogger::class)->log(AuditAction::USER_CREATED, auth()->user(), $this->getRecord(), [
            'name' => $this->getRecord()->name,
            'email' => $this->getRecord()->email,
            'is_admin' => $this->getRecord()->is_admin,
            'is_active' => $this->getRecord()->is_active,
        ]);
    }
}
