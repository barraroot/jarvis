<?php

namespace App\Filament\Resources\Agents;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Agents\Pages\CreateAgent;
use App\Filament\Resources\Agents\Pages\EditAgent;
use App\Filament\Resources\Agents\Pages\ListAgents;
use App\Filament\Resources\Agents\Schemas\AgentForm;
use App\Filament\Resources\Agents\Tables\AgentsTable;
use App\Models\Agent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Cadastrable agents (US-7.2): instructions, model, authorized tools,
 * delegation/execution limits and activation. Changes are audited.
 */
class AgentResource extends Resource
{
    protected static ?string $model = Agent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Ai;

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'agente';

    protected static ?string $pluralModelLabel = 'agentes';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return AgentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AgentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgents::route('/'),
            'create' => CreateAgent::route('/create'),
            'edit' => EditAgent::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
