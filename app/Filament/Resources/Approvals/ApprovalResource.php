<?php

namespace App\Filament\Resources\Approvals;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Approvals\Pages\ListApprovals;
use App\Filament\Resources\Approvals\Pages\ViewApproval;
use App\Filament\Resources\Approvals\Schemas\ApprovalInfolist;
use App\Filament\Resources\Approvals\Tables\ApprovalsTable;
use App\Models\Approval;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Approvals of every operator (US-12.1). Strictly read-only: only the owner
 * may approve or deny (US-8.3), so the admin never decides for another user.
 */
class ApprovalResource extends Resource
{
    protected static ?string $model = Approval::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHandRaised;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Operation;

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'aprovação';

    protected static ?string $pluralModelLabel = 'aprovações';

    public static function infolist(Schema $schema): Schema
    {
        return ApprovalInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ApprovalsTable::configure($table);
    }

    public static function canView(Model $record): bool
    {
        return true;
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
            'index' => ListApprovals::route('/'),
            'view' => ViewApproval::route('/{record}'),
        ];
    }
}
