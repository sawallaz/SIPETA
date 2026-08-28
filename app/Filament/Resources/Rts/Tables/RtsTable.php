<?php

namespace App\Filament\Resources\Rts\Tables;

use App\Models\KartuKeluarga;
use App\Models\Rt;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RtsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label('Nomor RT')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('areaUnit.name')
                    ->label('RW / Lingkungan')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('penduduks_count')
                    ->counts('penduduks')
                    ->label('Jumlah Penduduk')
                    ->badge()
                    ->color('info')
                    ->sortable(),
            ])
            ->actions([
                EditAction::make()
                    ->modalHeading('Ubah Data RT / RW')
                    ->modalWidth('md'),

                DeleteAction::make()
                    ->modalHeading('Hapus RT / RW')
                    ->before(function (DeleteAction $action, Rt $record) {
                        $pendudukCount = $record->penduduks()->count();
                        $kkCount = KartuKeluarga::query()->where('rt_id', $record->id)->count();

                        if ($pendudukCount > 0 || $kkCount > 0) {
                            Notification::make()
                                ->danger()
                                ->title('RT Tidak Dapat Dihapus')
                                ->body("RT {$record->number} tidak dapat dihapus karena sedang digunakan oleh {$pendudukCount} penduduk dan {$kkCount} Kartu Keluarga.")
                                ->persistent()
                                ->send();

                            $action->cancel();
                        }
                    }),
            ]);
    }
}
