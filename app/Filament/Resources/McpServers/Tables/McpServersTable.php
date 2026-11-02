<?php

namespace App\Filament\Resources\McpServers\Tables;

use App\Exceptions\InvalidMcpUrlException;
use App\Exceptions\McpConnectionException;
use App\Models\ConnectorStatus;
use App\Models\McpServer;
use App\Services\ConnectorService;
use App\Services\ConnectorStatusService;
use App\Services\McpClient;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class McpServersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Escopo')
                    ->placeholder('Global')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? 'Global' : "Pessoal — {$state}"),
                TextColumn::make('status.name')
                    ->label('Status')
                    ->badge()
                    ->color(fn (McpServer $record): string => match ($record->status?->slug) {
                        ConnectorStatus::CONNECTED => 'success',
                        ConnectorStatus::ERROR => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('url')
                    ->label('URL')
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('tool_definitions_count')
                    ->label('Ferramentas'),
                IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean(),
                TextColumn::make('last_synced_at')
                    ->label('Última sincronização')
                    ->since()
                    ->placeholder('Nunca')
                    ->sortable(),
                TextColumn::make('last_error')
                    ->label('Erro')
                    ->limit(40)
                    ->tooltip(fn (McpServer $record): ?string => $record->last_error)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('scope')
                    ->label('Escopo')
                    ->options(['global' => 'Global', 'personal' => 'Pessoal'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'global' => $query->whereNull('user_id'),
                        'personal' => $query->whereNotNull('user_id'),
                        default => $query,
                    }),
                TernaryFilter::make('is_active')
                    ->label('Ativo'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                self::syncAction(),
                self::testAction(),
                DeleteAction::make()
                    ->using(function (McpServer $record): bool {
                        app(ConnectorService::class)->delete($record, auth()->user());

                        return true;
                    }),
            ]);
    }

    /**
     * "Sincronizar": queue tool discovery (global connectors only).
     */
    public static function syncAction(): Action
    {
        return Action::make('sync')
            ->label('Sincronizar')
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn (McpServer $record): bool => $record->isGlobal() && ! $record->trashed())
            ->action(function (McpServer $record): void {
                app(ConnectorService::class)->resync($record);

                Notification::make()
                    ->title('Sincronização agendada')
                    ->body('As ferramentas do conector serão descobertas em segundo plano.')
                    ->success()
                    ->send();
            });
    }

    /**
     * "Testar": synchronous ping recording the connector status (US-9.4).
     */
    public static function testAction(): Action
    {
        return Action::make('test')
            ->label('Testar')
            ->icon(Heroicon::OutlinedBolt)
            ->visible(fn (McpServer $record): bool => ! $record->trashed())
            ->action(function (McpServer $record): void {
                $statuses = app(ConnectorStatusService::class);

                try {
                    McpClient::for($record)->ping();
                    $statuses->record($record, ConnectorStatus::CONNECTED);

                    Notification::make()
                        ->title('Conexão estabelecida')
                        ->success()
                        ->send();
                } catch (McpConnectionException|InvalidMcpUrlException $exception) {
                    $statuses->record($record, ConnectorStatus::ERROR, $exception->getMessage());

                    Notification::make()
                        ->title('Falha na conexão')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
