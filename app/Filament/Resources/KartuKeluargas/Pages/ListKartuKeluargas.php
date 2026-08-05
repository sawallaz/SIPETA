<?php

namespace App\Filament\Resources\KartuKeluargas\Pages;

use App\Filament\Resources\KartuKeluargas\KartuKeluargaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListKartuKeluargas extends ListRecords
{
    protected static string $resource = KartuKeluargaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
