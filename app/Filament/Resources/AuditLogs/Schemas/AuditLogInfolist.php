<?php

namespace App\Filament\Resources\AuditLogs\Schemas;

use App\Filament\Components\JsonEntry;
use App\Models\AuditLog;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Detail of an audit entry with its `properties` pretty-printed as JSON (US-12.3).
 */
class AuditLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Registro')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('action.slug')
                            ->label('Ação')
                            ->badge(),
                        TextEntry::make('user.name')
                            ->label('Ator')
                            ->placeholder('Sistema'),
                        TextEntry::make('subject')
                            ->label('Alvo')
                            ->state(fn (AuditLog $record): ?string => AuditLogInfolist::subjectLabel($record))
                            ->placeholder('—'),
                        TextEntry::make('created_at')
                            ->label('Data')
                            ->dateTime('d/m/Y H:i:s'),
                        TextEntry::make('ip_address')
                            ->label('IP')
                            ->placeholder('—'),
                        TextEntry::make('user_agent')
                            ->label('User agent')
                            ->placeholder('—'),
                    ]),
                Section::make('Propriedades')
                    ->schema([
                        JsonEntry::make('properties')
                            ->hiddenLabel()
                            ->placeholder('Sem propriedades'),
                    ]),
            ]);
    }

    public static function subjectLabel(AuditLog $record): ?string
    {
        if ($record->subject_type === null) {
            return null;
        }

        return class_basename($record->subject_type).' #'.$record->subject_id;
    }
}
