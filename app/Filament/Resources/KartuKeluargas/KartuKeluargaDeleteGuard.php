<?php

namespace App\Filament\Resources\KartuKeluargas;

use App\Models\KartuKeluarga;
use Illuminate\Validation\ValidationException;

/**
 * Guard that blocks deletion of a Kartu Keluarga while it still owns
 * dependent records.
 *
 * The SIPETA schema deliberately uses RESTRICT on every child foreign key
 * (penduduk.kk_id, kk_anggota.kk_id, kk_photos.kk_id, ocr_jobs.kk_id) so the
 * household's history can never be wiped by accident. We surface that
 * constraint to the operator as a human-readable message instead of letting
 * Laravel bubble up a raw SQLSTATE[23000] integrity-violation error.
 *
 * Call {@see assertDeletable()} from a DeleteAction / DeleteBulkAction
 * `->before()` hook. When dependencies remain it throws a
 * ValidationException, which Filament renders inline in the confirmation
 * modal and — crucially — prevents the underlying DELETE from running.
 */
final class KartuKeluargaDeleteGuard
{
    /**
     * @throws ValidationException when the household still has dependencies.
     */
    public static function assertDeletable(KartuKeluarga $record): void
    {
        $penduduk = $record->penduduks()->count();
        $anggota = $record->kkAnggotas()->count();
        $foto = $record->kkPhotos()->count();
        $ocr = $record->ocrJobs()->count();

        if ($penduduk === 0 && $anggota === 0 && $foto === 0 && $ocr === 0) {
            return;
        }

        $reasons = [];

        if ($penduduk > 0) {
            $reasons[] = sprintf(
                '%d anggota keluarga masih terhubung',
                $penduduk,
            );
        }

        if ($anggota > 0) {
            $reasons[] = sprintf(
                '%d riwayat keanggotaan ditemukan',
                $anggota,
            );
        }

        if ($foto > 0) {
            $reasons[] = sprintf(
                '%d foto KK tersimpan',
                $foto,
            );
        }

        if ($ocr > 0) {
            $reasons[] = sprintf(
                '%d riwayat OCR terhubung',
                $ocr,
            );
        }

        throw ValidationException::withMessages([
            'delete' => sprintf(
                'Kartu Keluarga %s tidak dapat dihapus. %s. Data tidak dihapus untuk menjaga integritas dan histori kependudukan.',
                $record->kk_number,
                implode('; ', $reasons),
            ),
        ]);
    }
}
