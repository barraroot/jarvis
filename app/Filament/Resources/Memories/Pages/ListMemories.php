<?php

namespace App\Filament\Resources\Memories\Pages;

use App\Filament\Resources\Memories\MemoryResource;
use Filament\Resources\Pages\ListRecords;

class ListMemories extends ListRecords
{
    protected static string $resource = MemoryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
