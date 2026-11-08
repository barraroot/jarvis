<?php

namespace App\Filament\Resources\WhatsappSessions\Tables;

use App\Models\ConnectorStatus;
use App\Models\WhatsappSession;
use App\Services\WhatsappSessionService;
use App\Support\Settings;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sessions of every operator (RF-40): owner, status, last error, last check,
 * today's sends against the daily cap and suspension. The only actions are
 * suspend/resume (RF-41), both confirmed and delegated to the service.
 */
class WhatsappSessionsTable
{
    /**
     * @var array<string, string>
     */
    public const array STATUS_LABELS = [
        ConnectorStatus::PENDING => 'Aguardando pareamento',
        ConnectorStatus::CONNECTED => 'Conectada',
        ConnectorStatus::DISCONNECTED => 'Desconectada',
        ConnectorStatus::ERROR => 'Erro',
    ];

    public static function configure(Table $table): Table
    {
        $dailyCap = app(Settings::class)->whatsappDailySendCap();

        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('user.name')
                    ->label('Usuário'),
                TextColumn::make('status.slug')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::STATUS_LABELS[$state] ?? '—')
                    ->color(fn (?string $state): string => match ($state) {
                        ConnectorStatus::CONNECTED => 'success',
                        ConnectorStatus::ERROR => 'danger',
                        ConnectorStatus::PENDING => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('last_error')
                    ->label('Último erro')
                    ->limit(60)
                    ->tooltip(fn (WhatsappSession $record): ?string => $record->last_error)
                    ->placeholder('—'),
                TextColumn::make('last_checked_at')
                    ->label('Última verificação')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Nunca')
                    ->sortable(),
                TextColumn::make('sends_today_count')
                    ->label('Envios hoje')
                    ->formatStateUsing(fn (?int $state): string => ((int) $state)." / {$dailyCap}"),
                IconColumn::make('suspended_at')
                    ->label('Suspensa')
                    ->state(fn (WhatsappSession $record): bool => $record->isSuspended())
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('suspended')
                    ->label('Suspensa')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('suspended_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('suspended_at'),
                    ),
            ])
            ->recordActions([
                self::suspendAction(),
                self::resumeAction(),
            ])
            ->toolbarActions([]);
    }

    /**
     * "Suspender": disconnects upstream, blocks every `whatsapp.*` tool and
     * delivery of the owner, notifies and audits (RF-41).
     */
    public static function suspendAction(): Action
    {
        return Action::make('suspend')
            ->label('Suspender')
            ->icon(Heroicon::OutlinedPauseCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('A sessão será desconectada e nenhuma ferramenta, envio ou agendamento WhatsApp do usuário será executado até a retomada.')
            ->visible(fn (WhatsappSession $record): bool => ! $record->isSuspended())
            ->action(function (WhatsappSession $record): void {
                app(WhatsappSessionService::class)->suspend(self::withSecrets($record), auth()->user());

                Notification::make()
                    ->title('Sessão suspensa')
                    ->success()
                    ->send();
            });
    }

    /**
     * "Retomar": lifts the suspension and lets the health check reconcile the status (RF-41).
     */
    public static function resumeAction(): Action
    {
        return Action::make('resume')
            ->label('Retomar')
            ->icon(Heroicon::OutlinedPlayCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('A suspensão será removida e o status da sessão será reconciliado na próxima verificação.')
            ->visible(fn (WhatsappSession $record): bool => $record->isSuspended())
            ->action(function (WhatsappSession $record): void {
                app(WhatsappSessionService::class)->resume(self::withSecrets($record), auth()->user());

                Notification::make()
                    ->title('Sessão retomada')
                    ->success()
                    ->send();
            });
    }

    /**
     * The listing never loads secrets; the service needs the token to talk to wuzapi.
     */
    private static function withSecrets(WhatsappSession $record): WhatsappSession
    {
        return WhatsappSession::query()->findOrFail($record->getKey());
    }
}
