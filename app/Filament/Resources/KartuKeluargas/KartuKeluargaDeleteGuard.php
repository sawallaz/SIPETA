<?php

namespace App\Filament\Resources\KartuKeluargas;

use App\Models\KartuKeluarga;
use Illuminate\Validation\ValidationException;

final class KartuKeluargaDeleteGuard
{
    /**
     * KK hanya boleh dihapus permanen apabila benar-benar merupakan
     * data kosong dan tidak mempunyai jejak administratif.
     *
     * Yang diperiksa:
     * - penduduk aktif/current
     * - histori kk_anggota
     * - arsip foto KK
     * - OCR job
     *
     * Jika KK sudah mempunyai histori, jangan DELETE.
     * KK tersebut harus masuk ke Riwayat KK.
     */
    public static function assertDeletable(KartuKeluarga $record): void
    {
        $pendudukCount = $record->penduduks()->count();

        if ($pendudukCount > 0) {
            throw ValidationException::withMessages([
                'kk' => sprintf(
                    'Kartu Keluarga %s masih memiliki %d anggota aktif. Hapus atau pindahkan seluruh anggota terlebih dahulu.',
                    $record->kk_number,
                    $pendudukCount,
                ),
            ]);
        }

        $historyCount = $record->kkAnggotas()->count();

        if ($historyCount > 0) {
            throw ValidationException::withMessages([
                'kk' => sprintf(
                    'Kartu Keluarga %s memiliki riwayat kependudukan dan tidak boleh dihapus permanen. KK tersebut akan disimpan sebagai riwayat.',
                    $record->kk_number,
                ),
            ]);
        }

        $photoCount = $record->kkPhotos()->count();

        if ($photoCount > 0) {
            throw ValidationException::withMessages([
                'kk' => sprintf(
                    'Kartu Keluarga %s masih memiliki arsip foto. Hapus arsip foto terlebih dahulu jika data ini memang merupakan data uji.',
                    $record->kk_number,
                ),
            ]);
        }

        $ocrJobCount = $record->ocrJobs()->count();

        if ($ocrJobCount > 0) {
            throw ValidationException::withMessages([
                'kk' => sprintf(
                    'Kartu Keluarga %s masih memiliki riwayat OCR dan tidak boleh dihapus permanen.',
                    $record->kk_number,
                ),
            ]);
        }
    }

    /**
     * Apakah KK masih mempunyai anggota aktif/current?
     */
    public static function hasCurrentMembers(KartuKeluarga $record): bool
    {
        return $record->penduduks()->exists();
    }

    /**
     * Apakah KK mempunyai histori perpindahan?
     */
    public static function hasHistory(KartuKeluarga $record): bool
    {
        return $record->kkAnggotas()->exists();
    }

    /**
     * KK dianggap sebagai histori apabila:
     *
     * - tidak mempunyai penduduk current
     * - tetapi mempunyai kk_anggota history.
     */
    public static function isHistorical(KartuKeluarga $record): bool
    {
        return ! self::hasCurrentMembers($record)
            && self::hasHistory($record);
    }
}
