<?php

namespace App\Filament\Resources\KartuKeluargas\Pages;

use App\Filament\Resources\KartuKeluargas\KartuKeluargaResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKartuKeluarga extends CreateRecord
{
    protected static string $resource = KartuKeluargaResource::class;

    public function getTitle(): string
    {
        return 'Tambah Kartu Keluarga';
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Kartu Keluarga berhasil disimpan';
    }
}
