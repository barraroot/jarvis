<?php

namespace App\Filament\Resources\UserPreferences\Schemas;

use App\Models\UserPreference;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Voice and language settings of one operator, with the same ranges as the
 * SPA preferences endpoint. The wake word can only be turned off here: turning
 * it on requires the operator's own privacy consent (US-4.4).
 */
class UserPreferenceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Idioma e voz')
                    ->columns(2)
                    ->schema([
                        TextInput::make('locale')
                            ->label('Idioma da interface')
                            ->required()
                            ->regex('/^[a-z]{2}-[A-Z]{2}$/'),
                        TextInput::make('speech_locale')
                            ->label('Idioma da fala')
                            ->required()
                            ->regex('/^[a-z]{2}-[A-Z]{2}$/'),
                        TextInput::make('voice_name')
                            ->label('Voz')
                            ->maxLength(120),
                        TextInput::make('speech_rate')
                            ->label('Velocidade da fala')
                            ->required()
                            ->numeric()
                            ->minValue(0.5)
                            ->maxValue(2.0)
                            ->step(0.05),
                        TextInput::make('silence_timeout_ms')
                            ->label('Timeout de silêncio (ms)')
                            ->required()
                            ->integer()
                            ->minValue(500)
                            ->maxValue(5000),
                        Toggle::make('spoken_responses_enabled')
                            ->label('Respostas faladas'),
                        Toggle::make('wake_word_enabled')
                            ->label('Wake word "Hey JARVIS"')
                            ->helperText('Só o operador pode ativar (exige consentimento de privacidade).')
                            ->disabled(fn (?UserPreference $record): bool => ! ($record?->wake_word_enabled ?? false)),
                    ]),
            ]);
    }
}
