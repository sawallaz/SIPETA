<?php

namespace App\Filament\Resources\KartuKeluargas\Pages;

use App\Filament\Resources\KartuKeluargas\KartuKeluargaResource;
use Filament\Resources\Pages\ViewRecord;

class ViewKartuKeluarga extends ViewRecord
{
    protected static string $resource = KartuKeluargaResource::class;

    public function getTitle(): string
    {
        return 'Detail Kartu Keluarga';
    }
}
