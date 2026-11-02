<?php

namespace App\Filament\Resources\UserPreferences;

use App\Filament\NavigationGroup;
use App\Filament\Resources\UserPreferences\Pages\EditUserPreference;
use App\Filament\Resources\UserPreferences\Pages\ListUserPreferences;
use App\Filament\Resources\UserPreferences\Schemas\UserPreferenceForm;
use App\Filament\Resources\UserPreferences\Tables\UserPreferencesTable;
use App\Models\AuditAction;
use App\Models\UserPreference;
use App\Support\AuditLogger;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Voice and language settings per operator (US-12.1, US-4.4, US-4.5). The
 * admin may adjust them and reset the wake-word consent, forcing the operator
 * to accept the privacy notice again before re-enabling it. Every change is
 * audited as `preference_updated`.
 */
class UserPreferenceResource extends Resource
{
    protected static ?string $model = UserPreference::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMicrophone;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::System;

    protected static ?int $navigationSort = 15;

    protected static ?string $modelLabel = 'configuração de voz';

    protected static ?string $pluralModelLabel = 'configurações de voz';

    public static function form(Schema $schema): Schema
    {
        return UserPreferenceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UserPreferencesTable::configure($table);
    }

    /**
     * The policy is owner-only (API); administrators manage every operator's settings here.
     */
    public static function canView(Model $record): bool
    {
        return true;
    }

    public static function canEdit(Model $record): bool
    {
        return true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function resetWakeWordConsentAction(): Action
    {
        return Action::make('resetWakeWordConsent')
            ->label('Resetar consentimento do wake word')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('O wake word será desativado e o operador precisará aceitar o aviso de privacidade novamente.')
            ->visible(fn (UserPreference $record): bool => $record->wake_word_enabled || $record->wake_word_consented_at !== null)
            ->action(function (UserPreference $record): void {
                $record->update([
                    'wake_word_enabled' => false,
                    'wake_word_consented_at' => null,
                ]);

                app(AuditLogger::class)->log(AuditAction::PREFERENCE_UPDATED, auth()->user(), $record, [
                    'owner_user_id' => $record->user_id,
                    'wake_word_consent_reset' => true,
                ]);
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUserPreferences::route('/'),
            'edit' => EditUserPreference::route('/{record}/edit'),
        ];
    }
}
