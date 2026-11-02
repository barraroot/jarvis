<?php

namespace App\Filament\Resources\Approvals\Schemas;

use App\Filament\Components\JsonEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Read-only detail of an approval request and its decision (US-12.1).
 */
class ApprovalInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Aprovação')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('user.name')
                            ->label('Usuário'),
                        TextEntry::make('tool.name')
                            ->label('Ferramenta'),
                        TextEntry::make('status.slug')
                            ->label('Status')
                            ->badge(),
                        TextEntry::make('task_id')
                            ->label('Tarefa')
                            ->formatStateUsing(fn (int $state): string => '#'.$state)
                            ->placeholder('—'),
                        TextEntry::make('requested_at')
                            ->label('Solicitada em')
                            ->dateTime('d/m/Y H:i:s'),
                        TextEntry::make('expires_at')
                            ->label('Expira em')
                            ->dateTime('d/m/Y H:i:s')
                            ->placeholder('—'),
                        TextEntry::make('decidedBy.name')
                            ->label('Decidida por')
                            ->placeholder('—'),
                        TextEntry::make('decided_at')
                            ->label('Decidida em')
                            ->dateTime('d/m/Y H:i:s')
                            ->placeholder('—'),
                        TextEntry::make('decision_reason')
                            ->label('Motivo')
                            ->placeholder('—'),
                    ]),
                Section::make('Entrada')
                    ->schema([
                        JsonEntry::make('input')
                            ->hiddenLabel(),
                    ]),
            ]);
    }
}
