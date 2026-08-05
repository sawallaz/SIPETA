<?php

namespace App\Filament\Resources\KartuKeluargas\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class KartuKeluargaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data Kartu Keluarga')
                    ->description('Informasi dasar Kartu Keluarga (KK).')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('kk_number')
                                    ->label('Nomor KK')
                                    ->required()
                                    ->unique('kartu_keluarga', 'kk_number', ignoreRecord: true)
                                    ->maxLength(16)
                                    ->regex('/^[0-9]{16}$/')
                                    ->helperText('Nomor Kartu Keluarga, 16 digit angka.'),
                                TextInput::make('postal_code')
                                    ->label('Kode Pos')
                                    ->nullable()
                                    ->maxLength(10)
                                    ->regex('/^[0-9]{5}$/')
                                    ->helperText('Kode pos 5 digit (opsional).'),
                            ]),
                        TextInput::make('address')
                            ->label('Alamat')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->helperText('Alamat lengkap sesuai Kartu Keluarga.'),
                        Textarea::make('notes')
                            ->label('Catatan')
                            ->nullable()
                            ->rows(4)
                            ->columnSpanFull()
                            ->helperText('Catatan tambahan (opsional).'),
                    ]),
            ]);
    }
}
