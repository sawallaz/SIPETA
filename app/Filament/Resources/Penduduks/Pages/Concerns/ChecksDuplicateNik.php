<?php

namespace App\Filament\Resources\Penduduks\Pages\Concerns;

use App\Enums\FamilyRelation;
use App\Enums\ResidentStatus;
use App\Filament\Resources\Penduduks\PendudukResource;
use App\Models\KartuKeluarga;
use App\Models\Penduduk;
use App\Services\PendudukKkService;
use Illuminate\Database\Eloquent\Model;

/**
 * State modal "NIK sudah terdaftar".
 *
 * Dipakai oleh Create Penduduk dan Edit Penduduk.
 *
 * @property-read Model|null $record
 */
trait ChecksDuplicateNik
{
    /**
     * Data Penduduk duplikat yang dibaca oleh modal Alpine ($wire.duplicateNik).
     *
     * Kosong ([]) berarti modal tertutup.
     *
     * @var array<string, mixed>
     */
    public array $duplicateNik = [];

    /**
     * Dipanggil dari afterStateUpdated() field nik (debounce 500ms).
     */
    public function checkDuplicateNik(mixed $value): void
    {
        $nik = preg_replace(
            '/\D/',
            '',
            (string) $value
        );

        /*
         * Belum 16 digit: tidak perlu query database.
         */
        if (
            strlen($nik) !== 16
            || preg_match('/^\d{16}$/', $nik) !== 1
        ) {
            $this->duplicateNik = [];

            return;
        }

        $query = Penduduk::query()
            ->with(['kartuKeluarga'])
            ->where('nik', $nik);

        /*
         * Saat EDIT: Penduduk yang sedang diedit bukan duplikat dirinya sendiri.
         */
        $record = $this->getDuplicateNikExclusion();

        if ($record !== null) {
            $query->whereKeyNot($record);
        }

        $penduduk = $query->first();

        if ($penduduk === null) {
            $this->duplicateNik = [];

            return;
        }

        $statusLabel = match ($penduduk->resident_status) {
            ResidentStatus::ACTIVE => 'Aktif',
            ResidentStatus::PINDAH => 'Pindah',
            ResidentStatus::MENINGGAL => 'Meninggal',
            default => is_string($penduduk->resident_status) ? $penduduk->resident_status : 'Aktif',
        };

        $ownerKkId = null;
        if (property_exists($this, 'ownerRecord') && $this->ownerRecord instanceof KartuKeluarga) {
            $ownerKkId = $this->ownerRecord->id;
        } elseif (property_exists($this, 'record') && $this->record instanceof KartuKeluarga) {
            $ownerKkId = $this->record->id;
        }

        $sameKk = $ownerKkId !== null && (int) $penduduk->kk_id === (int) $ownerKkId;
        $assignAllowed = $ownerKkId !== null && ! $sameKk && $penduduk->resident_status === ResidentStatus::ACTIVE;

        $this->duplicateNik = [
            'id' => $penduduk->getKey(),
            'nik' => (string) $penduduk->nik,
            'name' => (string) $penduduk->full_name,
            'kk_number' => $penduduk->kartuKeluarga?->kk_number ?? '-',
            'status' => $statusLabel,
            'same_kk' => $sameKk,
            'assign_allowed' => $assignAllowed,
            'view_url' => PendudukResource::getUrl(
                'index',
                ['tableAction' => 'view', 'tableActionRecord' => $penduduk->getKey()]
            ),
            'edit_url' => PendudukResource::getUrl(
                'edit',
                ['record' => $penduduk]
            ),
        ];
    }

    /**
     * Pindahkan / gunakan penduduk existing sebagai anggota KK ini.
     */
    public function assignExistingToKk(int $pendudukId, ?string $familyRelation = null): void
    {
        $penduduk = Penduduk::query()->find($pendudukId);
        if ($penduduk === null) {
            $this->closeDuplicateNik();

            return;
        }

        $kk = null;
        if (property_exists($this, 'ownerRecord') && $this->ownerRecord instanceof KartuKeluarga) {
            $kk = $this->ownerRecord;
        } elseif (property_exists($this, 'record') && $this->record instanceof KartuKeluarga) {
            $kk = $this->record;
        }

        if ($kk instanceof KartuKeluarga) {
            app(PendudukKkService::class)->save([
                'nik' => $penduduk->nik,
                'full_name' => $penduduk->full_name,
                'gender' => $penduduk->gender?->value ?? (string) $penduduk->gender,
                'birth_place' => $penduduk->birth_place,
                'birth_date' => $penduduk->birth_date?->format('Y-m-d'),
                'religion_id' => $penduduk->religion_id,
                'education_id' => $penduduk->education_id,
                'occupation_id' => $penduduk->occupation_id,
                'marital_status' => $penduduk->marital_status?->value ?? (string) $penduduk->marital_status,
                'family_relation' => $familyRelation ?? FamilyRelation::ANAK->value,
                'resident_status' => ResidentStatus::ACTIVE->value,
                'kk_id' => $kk->id,
            ], $penduduk);

            $this->closeDuplicateNik();
            $this->dispatch('close-modal', id: 'create-relation-record');
        }
    }

    /**
     * Tutup modal duplikat NIK.
     */
    public function closeDuplicateNik(): void
    {
        $this->duplicateNik = [];
    }

    /**
     * Primary key yang harus dikecualikan dari pencarian duplikat.
     */
    protected function getDuplicateNikExclusion(): int|string|null
    {
        $record = $this->record ?? null;

        if ($record === null || ! $record->exists) {
            return null;
        }

        return $record->getKey();
    }
}
