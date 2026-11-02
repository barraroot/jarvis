<?php

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Filament\Widgets\UsageByModelTable;
use App\Filament\Widgets\UsageByUserTable;
use App\Filament\Widgets\UsageStatsOverview;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Consumption and cost dashboard (US-5.3, US-12.1): totals of today/month,
 * ranking by user (with monthly limit) and by model, filtered by period.
 */
class UsageDashboard extends Page
{
    use HasFiltersForm;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::System;

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Consumo e custos';

    protected static ?string $title = 'Consumo e custos';

    protected static ?string $slug = 'usage';

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        DatePicker::make('from')
                            ->label('De')
                            ->default(now()->startOfMonth()),
                        DatePicker::make('until')
                            ->label('Até')
                            ->default(now()),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedSchema::make('filtersForm'),
                Grid::make(1)
                    ->schema(fn (): array => $this->getWidgetsSchemaComponents([
                        UsageStatsOverview::class,
                        UsageByUserTable::class,
                        UsageByModelTable::class,
                    ])),
            ]);
    }
}
