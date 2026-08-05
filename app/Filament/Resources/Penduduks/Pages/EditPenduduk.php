<?php

namespace App\Filament\Resources\Penduduks\Pages;

use App\Filament\Resources\Penduduks\PendudukResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPenduduk extends EditRecord
{
    protected static string $resource = PendudukResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
