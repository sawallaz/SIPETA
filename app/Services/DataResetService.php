<?php

namespace App\Services;

use App\Models\KkPhoto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * DataResetService — Hapus seluruh data kependudukan secara aman.
 *
 * Yang DIHAPUS:
 *   - Seluruh record kk_photos + file fisik foto di disk 'kk_uploads'.
 *   - Seluruh record kk_anggota.
 *   - Seluruh record ocr_jobs.
 *   - Seluruh record penduduk beserta relasi turunannya.
 *   - Seluruh record kartu_keluarga.
 *   - File debug OCR sementara di storage/app/ (debug_ocr_*.png).
 *
 * Yang DILINDUNGI (TIDAK DIHAPUS):
 *   - Tabel users  → Akun Super Admin dan staf tetap utuh.
 *   - Tabel settings → Konfigurasi sistem dan token Google Drive tetap ada.
 *   - Struktur schema database → Tidak ada migrate:fresh atau drop table.
 */
class DataResetService
{
    public function __construct(
        private readonly KkPhotoService $photoService,
    ) {}

    /**
     * Eksekusi reset data kependudukan secara lengkap dalam satu transaksi.
     *
     * @return array{kk_deleted: int, penduduk_deleted: int, photo_files_deleted: int}
     */
    public function resetAll(): array
    {
        $stats = [
            'kk_deleted'           => 0,
            'penduduk_deleted'     => 0,
            'photo_files_deleted'  => 0,
        ];

        Log::info('[DataReset] Memulai penghapusan seluruh data kependudukan.', [
            'operator_id' => auth()->id(),
        ]);

        // ---------------------------------------------------------------
        // 1. Hapus file fisik foto KK sebelum transaksi database dimulai.
        //    Ini mencegah file orphan jika transaksi DB gagal.
        // ---------------------------------------------------------------
        $photos = KkPhoto::all();

        foreach ($photos as $photo) {
            try {
                $diskName = $photo->storage_disk ?: KkPhotoService::DISK;
                $disk     = Storage::disk($diskName);

                foreach (array_unique(array_filter([
                    $photo->storage_path,
                    $photo->stored_filename,
                    $photo->thumbnail_filename,
                ])) as $path) {
                    if ($disk->exists($path)) {
                        $disk->delete($path);
                        $stats['photo_files_deleted']++;
                    }
                }
            } catch (\Throwable $e) {
                // File mungkin sudah terhapus secara manual; lanjutkan.
                Log::warning('[DataReset] Gagal hapus file foto: '.$e->getMessage(), [
                    'photo_id' => $photo->id,
                ]);
            }
        }

        // ---------------------------------------------------------------
        // 2. Hapus file debug OCR sementara di storage/app/.
        // ---------------------------------------------------------------
        $debugPatterns = [
            storage_path('app/debug_ocr_processed.png'),
            storage_path('app/debug_ocr_input.png'),
        ];

        foreach ($debugPatterns as $debugFile) {
            if (file_exists($debugFile)) {
                @unlink($debugFile);
            }
        }

        // ---------------------------------------------------------------
        // 3. Hapus seluruh record database dalam satu transaksi.
        //    Urutan disesuaikan dengan foreign key constraint.
        // ---------------------------------------------------------------
        DB::transaction(function () use (&$stats): void {
            // Nonaktifkan FK check sementara agar truncate aman pada SQLite.
            DB::statement('PRAGMA foreign_keys = OFF');

            try {
                // Tabel anak terlebih dahulu.
                DB::table('penduduk_status_histories')->delete();
                DB::table('penduduk_documents')->delete();
                DB::table('ocr_jobs')->delete();
                DB::table('kk_photos')->delete();
                DB::table('kk_anggota')->delete();

                $stats['penduduk_deleted'] = DB::table('penduduk')->count();
                DB::table('penduduk')->delete();

                $stats['kk_deleted'] = DB::table('kartu_keluarga')->count();
                DB::table('kartu_keluarga')->delete();
            } finally {
                DB::statement('PRAGMA foreign_keys = ON');
            }
        });

        Log::info('[DataReset] Penghapusan data kependudukan selesai.', $stats + [
            'operator_id' => auth()->id(),
        ]);

        return $stats;
    }
}
