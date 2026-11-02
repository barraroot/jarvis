<?php

namespace App\Filament\Resources\McpServers;

use App\Filament\NavigationGroup;
use App\Filament\Resources\McpServers\Pages\CreateMcpServer;
use App\Filament\Resources\McpServers\Pages\EditMcpServer;
use App\Filament\Resources\McpServers\Pages\ListMcpServers;
use App\Filament\Resources\McpServers\Pages\ViewMcpServer;
use App\Filament\Resources\McpServers\RelationManagers\ToolDefinitionsRelationManager;
use App\Filament\Resources\McpServers\Schemas\McpServerForm;
use App\Filament\Resources\McpServers\Tables\McpServersTable;
use App\Models\McpServer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * MCP connectors (US-9.1, US-9.3): admins create and edit global connectors
 * (`user_id` null) and classify their discovered tools; personal connectors
 * are visible read-only. Credentials are always masked.
 */
class McpServerResource extends Resource
{
    protected static ?string $model = McpServer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Ai;

    protected static ?int $navigationSort = 50;

    protected static ?string $modelLabel = 'conector MCP';

    protected static ?string $pluralModelLabel = 'conectores MCP';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return McpServerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return McpServersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ToolDefinitionsRelationManager::class,
        ];
    }

    /**
     * The panel is admin-only; personal connectors are inspectable read-only.
     */
    public static function canView(Model $record): bool
    {
        return true;
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof McpServer && $record->isGlobal() && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof McpServer && $record->isGlobal() && parent::canDelete($record);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMcpServers::route('/'),
            'create' => CreateMcpServer::route('/create'),
            'view' => ViewMcpServer::route('/{record}'),
            'edit' => EditMcpServer::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['status', 'user'])->withCount('toolDefinitions');
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
