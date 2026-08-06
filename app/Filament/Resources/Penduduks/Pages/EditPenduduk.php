<?php

namespace App\Filament\Resources\Penduduks\Pages;

use App\Filament\Resources\Penduduks\PendudukResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPenduduk extends EditRecord
{
    protected static string $resource = PendudukResource::class;

    public function getTitle(): string
    {
        return 'Ubah Data Penduduk';
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Perubahan data penduduk berhasil disimpan';
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Hapus')
                ->modalHeading('Hapus Data Penduduk')
                ->modalDescription('Data yang dihapus tidak dapat dikembalikan. Lanjutkan?')
                ->successNotificationTitle('Data penduduk berhasil dihapus'),
        ];
    }
}
