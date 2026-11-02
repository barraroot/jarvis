<?php

namespace App\Filament\Resources\AiProviders\Pages;

use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Models\AiProvider;
use App\Models\AuditAction;
use App\Support\AuditLogger;
use Filament\Resources\Pages\CreateRecord;

class CreateAiProvider extends CreateRecord
{
    protected static string $resource = AiProviderResource::class;

    protected function afterCreate(): void
    {
        /** @var AiProvider $provider */
        $provider = $this->getRecord();

        app(AuditLogger::class)->log(AuditAction::PROVIDER_CREATED, auth()->user(), $provider, [
            'name' => $provider->name,
            'slug' => $provider->slug,
            'driver' => $provider->driver->slug,
            'is_active' => $provider->is_active,
        ]);
    }
}
