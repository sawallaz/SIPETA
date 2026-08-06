<?php

namespace App\Filament\Resources\Penduduks\Pages;

use App\Filament\Resources\Penduduks\PendudukResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePenduduk extends CreateRecord
{
    protected static string $resource = PendudukResource::class;

    /**
     * Pre-select the Kartu Keluarga when arriving from the KK relation manager
     * ("Tambah Anggota"), so the new resident lands in the right family.
     *
     * Runs after the normal fill so component defaults are preserved.
     */
    protected function afterFill(): void
    {
        $kkId = request()->query('kk_id');

        if (blank($kkId)) {
            return;
        }

        $this->form->fillPartially(['kk_id' => $kkId], ['kk_id']);
    }
}
