<?php

namespace App\Filament\Resources\McpServers\Pages;

use App\Filament\Resources\McpServers\McpServerResource;
use App\Filament\Resources\McpServers\Tables\McpServersTable;
use App\Models\McpServer;
use App\Services\ConnectorService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Global connectors only ({@see McpServerResource::canEdit()}); updates and
 * removal go through {@see ConnectorService} (audit + resync on endpoint change).
 */
class EditMcpServer extends EditRecord
{
    protected static string $resource = McpServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            McpServersTable::syncAction(),
            McpServersTable::testAction(),
            DeleteAction::make()
                ->using(function (McpServer $record): bool {
                    app(ConnectorService::class)->delete($record, auth()->user());

                    return true;
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var McpServer $record */
        return app(ConnectorService::class)->update($record, $data, auth()->user());
    }
}
