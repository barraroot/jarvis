<?php

namespace App\Filament\Resources\Memories;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Memories\Pages\ListMemories;
use App\Filament\Resources\Memories\Tables\MemoriesTable;
use App\Models\Memory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Memories of every operator (US-12.1): the admin inspects content, status,
 * category and origin, and may delete (audited as `memory_deleted`, embedding
 * removed). Content is never editable here (US-6.4).
 */
class MemoryResource extends Resource
{
    protected static ?string $model = Memory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookmarkSquare;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Operation;

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'memória';

    protected static ?string $pluralModelLabel = 'memórias';

    protected static ?string $recordTitleAttribute = 'content';

    public static function table(Table $table): Table
    {
        return MemoriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMemories::route('/'),
        ];
    }
}
