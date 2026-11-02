<?php

namespace App\Filament\Resources\UserPreferences\Tables;

use App\Filament\Resources\UserPreferences\UserPreferenceResource;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Voice settings of every operator. Edit and the wake-word consent reset are the only actions.
 */
class UserPreferencesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('user_id')
            ->columns([
                TextColumn::make('user.name')
                    ->label('Usuário')
                    ->searchable(),
                TextColumn::make('locale')
                    ->label('Idioma'),
                TextColumn::make('speech_locale')
                    ->label('Idioma da fala'),
                TextColumn::make('voice_name')
                    ->label('Voz')
                    ->placeholder('Padrão do navegador'),
                TextColumn::make('speech_rate')
                    ->label('Velocidade'),
                IconColumn::make('spoken_responses_enabled')
                    ->label('Respostas faladas')
                    ->boolean(),
                IconColumn::make('wake_word_enabled')
                    ->label('Wake word')
                    ->boolean(),
                TextColumn::make('wake_word_consented_at')
                    ->label('Consentimento em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
            ])
            ->filters([
                TernaryFilter::make('wake_word_enabled')
                    ->label('Wake word'),
            ])
            ->recordActions([
                EditAction::make(),
                UserPreferenceResource::resetWakeWordConsentAction(),
            ])
            ->toolbarActions([]);
    }
}
