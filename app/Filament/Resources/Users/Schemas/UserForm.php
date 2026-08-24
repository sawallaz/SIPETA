<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\UserResource;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make()
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email/Username')
                            ->email()
                            ->required()
                            ->unique(UserResource::getModel(), ignoreRecord: true)
                            ->maxLength(255),
                    ])
                    ->columns(['default' => 2]),
                Grid::make()
                    ->schema([
                        TextInput::make('password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->required(fn ($livewire): bool => $livewire instanceof CreateUser)
                            ->minLength(8)
                            ->dehydrateStateUsing(fn ($state): ?string => filled($state) ? bcrypt($state) : null)
                            ->dehydrated(fn ($state): bool => filled($state))
                            ->same('password_confirmation'),
                        TextInput::make('password_confirmation')
                            ->label('Konfirmasi Password')
                            ->password()
                            ->revealable()
                            ->required(fn ($livewire): bool => $livewire instanceof CreateUser),
                    ])
                    ->columns(['default' => 2]),
            ]);
    }
}
