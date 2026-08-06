<?php

namespace App\Filament\Resources\KartuKeluargas\Pages;

use App\Filament\Resources\KartuKeluargas\KartuKeluargaResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditKartuKeluarga extends EditRecord
{
    protected static string $resource = KartuKeluargaResource::class;

    public function getTitle(): string
    {
        return 'Ubah Kartu Keluarga';
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Perubahan Kartu Keluarga berhasil disimpan';
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Hapus')
                ->modalHeading('Hapus Kartu Keluarga')
                ->modalDescription('Data yang dihapus tidak dapat dikembalikan. Lanjutkan?')
                ->successNotificationTitle('Kartu Keluarga berhasil dihapus'),
        ];
    }
}
