<?php

namespace App\Filament\Resources\Conversations\Schemas;

use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Read-only detail view of a conversation (US-12.1).
 */
class ConversationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Conversa')
                    ->schema([
                        TextEntry::make('title')
                            ->label('Título'),
                        TextEntry::make('user.name')
                            ->label('Usuário'),
                        TextEntry::make('model.name')
                            ->label('Modelo')
                            ->placeholder('Padrão global'),
                        TextEntry::make('last_activity_at')
                            ->label('Última atividade')
                            ->since(),
                        TextEntry::make('created_at')
                            ->label('Criada em')
                            ->dateTime('d/m/Y H:i'),
                        KeyValueEntry::make('meta')
                            ->label('Meta'),
                    ])
                    ->columns(2),
            ]);
    }
}
