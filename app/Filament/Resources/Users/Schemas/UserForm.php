<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public const int MINIMUM_PASSWORD_LENGTH = 12;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identificação')
                    ->columns(2)
                    ->components([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('E-mail')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('password')
                            ->label('Senha')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->minLength(self::MINIMUM_PASSWORD_LENGTH)
                            ->rule(Password::min(self::MINIMUM_PASSWORD_LENGTH))
                            ->helperText(fn (string $operation): string => $operation === 'create'
                                ? sprintf('Mínimo de %d caracteres.', self::MINIMUM_PASSWORD_LENGTH)
                                : sprintf('Deixe em branco para manter a senha atual. Mínimo de %d caracteres.', self::MINIMUM_PASSWORD_LENGTH))
                            ->columnSpanFull(),
                    ]),
                Section::make('Acesso e limites')
                    ->columns(2)
                    ->components([
                        Toggle::make('is_admin')
                            ->label('Administrador')
                            ->helperText('Libera o painel /admin.')
                            ->default(false)
                            ->rule(self::lastActiveAdminRule()),
                        Toggle::make('is_active')
                            ->label('Ativo')
                            ->helperText('Contas inativas não conseguem entrar e perdem as sessões abertas.')
                            ->default(true)
                            ->rule(self::lastActiveAdminRule()),
                        TextInput::make('monthly_cost_limit')
                            ->label('Limite mensal de custo')
                            ->numeric()
                            ->prefix('R$')
                            ->minValue(0)
                            ->step(0.01)
                            ->nullable()
                            ->helperText('Em branco = sem limite.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Prevents removing the admin flag from, or deactivating, the last active administrator.
     */
    private static function lastActiveAdminRule(): Closure
    {
        return fn (?User $record, Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record, $get): void {
            if ($record === null || ! UserResource::isLastActiveAdmin($record)) {
                return;
            }

            if ((bool) $get('is_admin') && (bool) $get('is_active')) {
                return;
            }

            $fail('Este é o último administrador ativo. Promova outro usuário antes de remover a flag de administrador ou desativá-lo.');
        };
    }
}
