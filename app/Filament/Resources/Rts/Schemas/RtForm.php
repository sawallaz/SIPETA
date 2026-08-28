<?php

namespace App\Filament\Resources\Rts\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class RtForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('number')
                    ->label('Nomor RT')
                    ->placeholder('Contoh: 01 atau 001')
                    ->helperText('Nomor RT wilayah (format 2-3 digit)')
                    ->required()
                    ->maxLength(10),

                Select::make('area_unit_id')
                    ->label('RW / Lingkungan')
                    ->relationship('areaUnit', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->createOptionForm([
                        TextInput::make('name')
                            ->label('Nama RW / Lingkungan')
                            ->placeholder('Contoh: RW 01 atau Lingkungan 1')
                            ->required(),
                        Select::make('type')
                            ->label('Tipe Wilayah')
                            ->options([
                                'rw' => 'RW',
                                'lingkungan' => 'Lingkungan',
                                'dusun' => 'Dusun',
                            ])
                            ->default('rw')
                            ->required(),
                    ]),
            ]);
    }
}
