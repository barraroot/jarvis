<?php

namespace App\Filament\Resources\McpServers\Pages;

use App\Filament\Resources\McpServers\McpServerResource;
use App\Filament\Resources\McpServers\Tables\McpServersTable;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only view (personal connectors are never editable by the admin).
 */
class ViewMcpServer extends ViewRecord
{
    protected static string $resource = McpServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            McpServersTable::testAction(),
            EditAction::make(),
        ];
    }
}
