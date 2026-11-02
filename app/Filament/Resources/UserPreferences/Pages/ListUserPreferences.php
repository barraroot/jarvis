<?php

namespace App\Filament\Resources\UserPreferences\Pages;

use App\Filament\Resources\UserPreferences\UserPreferenceResource;
use Filament\Resources\Pages\ListRecords;

class ListUserPreferences extends ListRecords
{
    protected static string $resource = UserPreferenceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
