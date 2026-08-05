<?php

namespace App\Filament\Resources\KartuKeluargas\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class KartuKeluargasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kk_number')
                    ->label('Nomor KK')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('address')
                    ->label('Alamat')
                    ->searchable()
                    ->wrap()
                    ->limit(50),
                TextColumn::make('postal_code')
                    ->label('Kode Pos')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('kkAnggotas_count')
                    ->label('Jumlah Anggota')
                    ->counts('kkAnggotas')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->date('d M Y')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
