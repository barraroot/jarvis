<?php

namespace App\Filament\Resources\Tasks\Schemas;

use App\Filament\Components\JsonEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Read-only detail of a task: request, status, progress, result and error (US-12.1).
 */
class TaskInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Tarefa')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('title')
                            ->label('Título'),
                        TextEntry::make('user.name')
                            ->label('Usuário'),
                        TextEntry::make('status.slug')
                            ->label('Status')
                            ->badge(),
                        TextEntry::make('agent.name')
                            ->label('Agente')
                            ->placeholder('—'),
                        TextEntry::make('progress')
                            ->label('Progresso')
                            ->suffix('%'),
                        TextEntry::make('timeout_seconds')
                            ->label('Timeout (s)')
                            ->placeholder('Padrão'),
                        TextEntry::make('conversation_id')
                            ->label('Conversa')
                            ->formatStateUsing(fn (int $state): string => '#'.$state)
                            ->placeholder('—'),
                        TextEntry::make('retry_of_task_id')
                            ->label('Retentativa de')
                            ->formatStateUsing(fn (int $state): string => '#'.$state)
                            ->placeholder('—'),
                        TextEntry::make('queued_at')
                            ->label('Enfileirada em')
                            ->dateTime('d/m/Y H:i:s')
                            ->placeholder('—'),
                        TextEntry::make('started_at')
                            ->label('Iniciada em')
                            ->dateTime('d/m/Y H:i:s')
                            ->placeholder('—'),
                        TextEntry::make('completed_at')
                            ->label('Concluída em')
                            ->dateTime('d/m/Y H:i:s')
                            ->placeholder('—'),
                        TextEntry::make('request')
                            ->label('Pedido')
                            ->columnSpanFull(),
                    ]),
                Section::make('Resultado')
                    ->schema([
                        TextEntry::make('resultType.slug')
                            ->label('Tipo')
                            ->badge()
                            ->placeholder('—'),
                        JsonEntry::make('result')
                            ->label('Resultado'),
                        TextEntry::make('error')
                            ->label('Erro')
                            ->color('danger')
                            ->placeholder('—'),
                    ]),
            ]);
    }
}
