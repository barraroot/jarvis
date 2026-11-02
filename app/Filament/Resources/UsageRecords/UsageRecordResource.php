<?php

namespace App\Filament\Resources\UsageRecords;

use App\Filament\NavigationGroup;
use App\Filament\Resources\UsageRecords\Pages\ListUsageRecords;
use App\Filament\Resources\UsageRecords\Tables\UsageRecordsTable;
use App\Models\UsageRecord;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Every AI call recorded in `usage_records` (US-5.3). Read-only: records are
 * written by the usage recorder only; the admin filters and exports them.
 */
class UsageRecordResource extends Resource
{
    protected static ?string $model = UsageRecord::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::System;

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'registro de consumo';

    protected static ?string $pluralModelLabel = 'registros de consumo';

    public static function table(Table $table): Table
    {
        return UsageRecordsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsageRecords::route('/'),
        ];
    }
}
