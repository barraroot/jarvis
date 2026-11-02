<?php

namespace App\Filament\Resources\McpServers\Pages;

use App\Filament\Resources\McpServers\McpServerResource;
use App\Services\ConnectorService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin-created connectors are always global (`user_id` null); creation is
 * audited and dispatches tool discovery through {@see ConnectorService}.
 */
class CreateMcpServer extends CreateRecord
{
    protected static string $resource = McpServerResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(ConnectorService::class)->create($data, owner: null, actor: auth()->user());
    }
}
