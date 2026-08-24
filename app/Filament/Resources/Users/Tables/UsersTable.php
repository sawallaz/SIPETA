<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email/Username')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('role')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn (UserRole $state): string => match ($state) {
                        UserRole::SUPER_ADMIN => 'Super Admin',
                        UserRole::OPERATOR => 'Operator',
                    })
                    ->color(fn (UserRole $state): string => match ($state) {
                        UserRole::SUPER_ADMIN => 'danger',
                        UserRole::OPERATOR => 'info',
                    }),
                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label('Role')
                    ->options([
                        UserRole::SUPER_ADMIN->value => 'Super Admin',
                        UserRole::OPERATOR->value => 'Operator',
                    ]),
            ])
            ->actions([
                EditAction::make()
                    ->label('Ubah'),
                Action::make('change_password')
                    ->label('Ubah Password')
                    ->icon('heroicon-o-lock-closed')
                    ->form([
                        TextInput::make('password')
                            ->label('Password Baru')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(8)
                            ->same('password_confirmation'),
                        TextInput::make('password_confirmation')
                            ->label('Konfirmasi Password')
                            ->password()
                            ->revealable()
                            ->required(),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record) => "Ubah password {$record->name}")
                    ->modalSubmitActionLabel('Simpan')
                    ->action(function (User $record, array $data): void {
                        $record->forceFill([
                            'password' => $data['password'],
                        ])->save();
                    })
                    ->hidden(fn (User $record): bool => $record->getAuthIdentifier() === auth()->id()),
                DeleteAction::make()
                    ->label('Hapus')
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record) => "Hapus operator {$record->name}?")
                    ->modalDescription('Tindakan ini tidak dapat dibatalkan.')
                    ->modalSubmitActionLabel('Hapus'),
            ]);
    }
}
