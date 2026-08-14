<?php

namespace App\Filament\Resources\Penduduks\Pages;

use App\Filament\Resources\Penduduks\Pages\Concerns\SavesPendudukThroughKkService;
use App\Filament\Resources\Penduduks\PendudukResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePenduduk extends CreateRecord
{
    use SavesPendudukThroughKkService;

    protected static string $resource = PendudukResource::class;

    public function getTitle(): string
    {
        return 'Tambah Penduduk';
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Data penduduk berhasil disimpan';
    }

    /**
     * Ketika form dibuka dari Relation Manager KK:
     *
     * KK
     *  ↓
     * Tambah Anggota
     *  ↓
     * CreatePenduduk
     *  ↓
     * kk_id otomatis diisi
     */
    protected function afterFill(): void
    {
        $kkId = request()->query('kk_id');

        if (blank($kkId)) {
            return;
        }

        $this->form->fillPartially(
            ['kk_id' => $kkId],
            ['kk_id'],
        );
    }

    /**
     * Semua proses create penduduk dilewatkan
     * melalui service agar aturan NIK + KK + histori
     * tidak berbeda dengan jalur lainnya.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return $this->savePendudukThroughService($data);
    }
}
