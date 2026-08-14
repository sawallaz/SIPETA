<?php

namespace App\Filament\Resources\Penduduks\Pages;

use App\Filament\Resources\Penduduks\Pages\Concerns\SavesPendudukThroughKkService;
use App\Filament\Resources\Penduduks\PendudukResource;
use App\Models\Penduduk;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPenduduk extends EditRecord
{
    use SavesPendudukThroughKkService;

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
                ->modalDescription(
                    'Data yang dihapus tidak dapat dikembalikan. Lanjutkan?'
                )
                ->successNotificationTitle(
                    'Data penduduk berhasil dihapus'
                ),
        ];
    }

    /**
     * Simpan perubahan melalui service yang sama
     * dengan proses CreatePenduduk.
     *
     * Dengan demikian:
     *
     * Edit Penduduk
     *      ↓
     * ganti KK?
     *      ↓
     * YA
     *      ↓
     * KK lama → KELUAR
     * KK baru → AKTIF
     * Penduduk.kk_id → KK baru
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(
        Model $record,
        array $data,
    ): Model {
        /** @var Penduduk $record */
        return $this->savePendudukThroughService(
            $data,
            $record,
        );
    }
}
