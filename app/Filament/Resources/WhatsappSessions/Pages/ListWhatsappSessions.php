<?php

namespace App\Filament\Resources\WhatsappSessions\Pages;

use App\Filament\Resources\WhatsappSessions\WhatsappSessionResource;
use Filament\Resources\Pages\ListRecords;

class ListWhatsappSessions extends ListRecords
{
    protected static string $resource = WhatsappSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
