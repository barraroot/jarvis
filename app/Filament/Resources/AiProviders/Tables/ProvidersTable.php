<?php

namespace App\Filament\Resources\AiProviders\Tables;

use App\Filament\Actions\ToggleActiveBulkActions;
use App\Models\AiProvider;
use App\Models\AuditAction;
use App\Services\ProviderRegistry;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Throwable;

use function Laravel\Ai\agent;

class ProvidersTable
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
                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('driver.name')
                    ->label('Driver')
                    ->sortable(),
                TextColumn::make('masked_api_key')
                    ->label('Chave')
                    ->placeholder('—'),
                IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean(),
                TextColumn::make('last_tested_at')
                    ->label('Último teste')
                    ->since()
                    ->placeholder('Nunca')
                    ->sortable(),
                TextColumn::make('last_test_error')
                    ->label('Erro do último teste')
                    ->limit(40)
                    ->tooltip(fn (AiProvider $record): ?string => $record->last_test_error)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Ativo'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('testConnection')
                    ->label('Testar conexão')
                    ->icon(Heroicon::OutlinedBolt)
                    ->action(fn (AiProvider $record) => self::testConnection($record)),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ...ToggleActiveBulkActions::make(AuditAction::PROVIDER_UPDATED),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Run a minimal prompt against the provider and persist the result (US-5.1).
     */
    private static function testConnection(AiProvider $provider): void
    {
        app(ProviderRegistry::class)->register();

        try {
            agent(instructions: 'You are a connectivity check.')->prompt(
                'Reply with OK.',
                provider: $provider->slug,
                timeout: 10,
            );

            $provider->update([
                'last_tested_at' => now(),
                'last_test_error' => null,
            ]);

            Notification::make()
                ->title('Conexão estabelecida')
                ->body('O provedor respondeu à chamada de teste.')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            $provider->update([
                'last_tested_at' => now(),
                'last_test_error' => Str::limit($exception->getMessage(), 500),
            ]);

            Notification::make()
                ->title('Falha na conexão')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }
}
