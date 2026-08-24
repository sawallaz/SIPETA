<?php

namespace App\Filament\Resources\KartuKeluargas\Pages;

use App\Filament\Pages\KartuKeluargaHistory;
use App\Filament\Resources\KartuKeluargas\KartuKeluargaResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListKartuKeluargas extends ListRecords
{
    protected static string $resource = KartuKeluargaResource::class;

    public function getTitle(): string
    {
        return 'Kartu Keluarga';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('history')
                ->label('Riwayat KK')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->url(fn (): string => KartuKeluargaHistory::getUrl()),
            CreateAction::make()
                ->label('Tambah Kartu Keluarga'),
        ];
    }
}
