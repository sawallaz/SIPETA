<?php

namespace App\Filament\Resources\KartuKeluargas\Pages\Concerns;

use App\Filament\Resources\KartuKeluargas\KartuKeluargaResource;
use App\Models\KartuKeluarga;
use Illuminate\Database\Eloquent\Model;

/**
 * State modal "Nomor KK sudah terdaftar".
 *
 * Dipakai oleh Create KK dan Edit KK karena keduanya berbagi
 * KartuKeluargaForm::components(). Tanpa trait ini, field kk_number
 * yang memanggil $livewire->checkDuplicateKk() akan fatal
 * (Call to undefined method) pada halaman Edit.
 *
 * Properti WAJIB public: Livewire hanya melakukan dehydrate/hydrate
 * pada properti public. Properti protected akan reset ke [] pada
 * setiap re-render berikutnya sehingga modal langsung hilang.
 *
 * @property-read Model|null $record
 */
trait ChecksDuplicateKkNumber
{
    /**
     * Data KK duplikat yang dibaca oleh modal Alpine ($wire.duplicateKk).
     *
     * Kosong ([]) berarti modal tertutup.
     *
     * @var array<string, mixed>
     */
    public array $duplicateKk = [];

    /**
     * Dipanggil dari afterStateUpdated() field kk_number (debounce 500ms).
     */
    public function checkDuplicateKk(mixed $value): void
    {
        $kkNumber = preg_replace(
            '/\D/',
            '',
            (string) $value
        );

        /*
         * Belum 16 digit: tidak perlu query database.
         */
        if (
            strlen($kkNumber) !== 16
            || preg_match('/^\d{16}$/', $kkNumber) !== 1
        ) {
            $this->duplicateKk = [];

            return;
        }

        $query = KartuKeluarga::query()
            ->with(['rt.areaUnit'])
            ->where('kk_number', $kkNumber);

        /*
         * Saat EDIT: KK yang sedang diedit bukan duplikat dirinya sendiri.
         *
         * whereKeyNot() — whereKey() mengabaikan operator kedua dan
         * menghasilkan predikat `id = '!='` yang salah.
         */
        $record = $this->getDuplicateKkExclusion();

        if ($record !== null) {
            $query->whereKeyNot($record);
        }

        $kk = $query->first();

        if ($kk === null) {
            $this->duplicateKk = [];

            return;
        }

        $this->duplicateKk = [
            'id' => $kk->getKey(),
            'number' => (string) $kk->kk_number,
            'kepala' => $kk->kepalaKeluarga?->full_name
                ?? 'Belum ditentukan',
            'address' => (string) ($kk->address ?? '-'),
            'rt' => $kk->nomor_rt ? 'RT '.$kk->nomor_rt : '-',
            'rw' => (string) ($kk->nama_wilayah ?? '-'),
            'wilayah' => $kk->rt_rw_label ?? '-',
            'member_count' => $kk->jumlah_anggota.' orang',
            'view_url' => KartuKeluargaResource::getUrl(
                'view',
                ['record' => $kk]
            ),
            'edit_url' => KartuKeluargaResource::getUrl(
                'edit',
                ['record' => $kk]
            ),
        ];
    }

    /**
     * Tutup modal duplikat.
     */
    public function closeDuplicateKk(): void
    {
        $this->duplicateKk = [];
    }

    /**
     * Primary key yang harus dikecualikan dari pencarian duplikat.
     *
     * Create: null (tidak ada record).
     * Edit  : id record yang sedang dibuka.
     */
    protected function getDuplicateKkExclusion(): int|string|null
    {
        $record = $this->record ?? null;

        if ($record === null || ! $record->exists) {
            return null;
        }

        return $record->getKey();
    }
}
