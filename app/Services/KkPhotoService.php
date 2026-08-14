<?php

namespace App\Services;

use App\Enums\PhotoType;
use App\Models\KartuKeluarga;
use App\Models\KkPhoto;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;

class KkPhotoService
{
    public const DISK = 'kk_uploads';

    public const THUMBNAIL_MAX_WIDTH = 320;

    private const JPEG_SIGNATURE = "\xFF\xD8\xFF";

    private const PNG_SIGNATURE = "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A";

    public function __construct(
        private readonly FilesystemManager $filesystem,
    ) {}

    /**
     * Simpan foto KK baru sebagai arsip versi.
     *
     * Jika KK sudah memiliki foto aktif, foto lama diarsipkan
     * (is_active = false): file fisik dan record tetap tersimpan
     * sebagai riwayat dokumen KK. Foto baru menjadi satu-satunya
     * foto aktif.
     *
     * Pada jalur ganti foto, foto lama TIDAK dihapus.
     */
    public function storeForKk(
        int $kkId,
        string $sourcePath,
        ?string $originalFilename = null,
        ?int $operatorId = null,
    ): KkPhoto {
        $disk = $this->filesystem->disk(self::DISK);

        $bytes = $disk->get($sourcePath);

        if ($bytes === null || $bytes === '') {
            throw new RuntimeException(
                sprintf(
                    'Foto KK tidak dapat dibaca dari penyimpanan (%s).',
                    $sourcePath,
                ),
            );
        }

        $extension = $this->extensionFor($originalFilename, $bytes);
        $stored = Str::uuid().'.'.$extension;

        /*
         * Simpan file baru terlebih dahulu.
         *
         * Ini penting:
         * kalau proses pembuatan file baru gagal, foto lama belum disentuh.
         */
        $disk->put($stored, $bytes);

        try {
            $thumbnail = $this->writeThumbnail(
                $disk,
                $bytes,
                $stored,
            );

            return DB::transaction(function () use (
                $kkId,
                $sourcePath,
                $originalFilename,
                $operatorId,
                $stored,
                $thumbnail,
                $bytes,
                $disk,
            ): KkPhoto {
                /*
                 * Cari semua foto aktif lama.
                 *
                 * Normalnya hanya satu, tetapi kita bersihkan semuanya
                 * supaya database kembali konsisten.
                 */
                $oldPhotos = KkPhoto::query()
                    ->where('kk_id', $kkId)
                    ->where('is_active', true)
                    ->get();

                $photo = KkPhoto::create([
                    'kk_id' => $kkId,
                    'original_filename' => $originalFilename ?: $stored,
                    'stored_filename' => $stored,
                    'thumbnail_filename' => $thumbnail,
                    'mime_type' => $this->mimeFor($bytes),
                    'file_size' => strlen($bytes),
                    'sha256_hash' => hash('sha256', $bytes),
                    'storage_disk' => self::DISK,
                    'storage_path' => $stored,
                    'photo_type' => PhotoType::KK_PHOTO->value,
                    'is_active' => true,
                    'uploaded_by' => $operatorId,
                    'uploaded_at' => now(),
                    'ocr_job_id' => null,
                ]);

                /*
                 * Foto lama diarsipkan (is_active = false) setelah
                 * foto baru berhasil dibuat. File fisik dan record
                 * tetap tersimpan sebagai riwayat, tidak dihapus.
                 */
                foreach ($oldPhotos as $oldPhoto) {
                    $oldPhoto->update(['is_active' => false]);
                }

                /*
                 * File sementara dari FileUpload tidak diperlukan lagi.
                 * Jangan hapus apabila ternyata path-nya sama dengan file baru.
                 */
                if ($sourcePath !== $stored && $disk->exists($sourcePath)) {
                    $disk->delete($sourcePath);
                }

                return $photo;
            });
        } catch (\Throwable $e) {
            /*
             * Kalau proses database gagal, bersihkan file baru
             * supaya tidak meninggalkan file yatim.
             */
            if ($disk->exists($stored)) {
                $disk->delete($stored);
            }

            if ($thumbnail !== null && $disk->exists($thumbnail)) {
                $disk->delete($thumbnail);
            }

            throw $e;
        }
    }

    /**
     * Simpan foto KK dari hasil upload langsung (TemporaryUploadedFile)
     * tanpa bergantung pada path file yang sudah tersimpan di disk.
     *
     * Dipakai ketika FileUpload di-set storeFiles(false), sehingga nilai
     * form berupa TemporaryUploadedFile yang kita serahkan ke service.
     *
     * Foto aktif lama diarsipkan (is_active = false); file fisik dan record
     * tetap tersimpan sebagai riwayat dokumen — tidak dihapus, sejalan dengan
     * storeForKk().
     */
    public function storeUploadedFileForKk(
        int $kkId,
        TemporaryUploadedFile $file,
        ?int $operatorId = null,
    ): KkPhoto {
        $disk = $this->filesystem->disk(self::DISK);

        $bytes = $file->get();

        if ($bytes === null || $bytes === '') {
            throw new RuntimeException(
                'Foto KK yang diunggah kosong atau tidak dapat dibaca.'
            );
        }

        $originalFilename = $file->getClientOriginalName();

        if (
            ! is_string($originalFilename)
            || $originalFilename === ''
            || ! mb_check_encoding($originalFilename, 'UTF-8')
        ) {
            $originalFilename = null;
        }

        $extension = $this->extensionFor($originalFilename, $bytes);
        $stored = Str::uuid().'.'.$extension;

        $disk->put($stored, $bytes);

        try {
            $thumbnail = $this->writeThumbnail($disk, $bytes, $stored);

            return DB::transaction(function () use (
                $kkId,
                $originalFilename,
                $operatorId,
                $stored,
                $thumbnail,
                $bytes,
            ): KkPhoto {
                $oldPhotos = KkPhoto::query()
                    ->where('kk_id', $kkId)
                    ->where('is_active', true)
                    ->get();

                $photo = KkPhoto::create([
                    'kk_id' => $kkId,
                    'original_filename' => $originalFilename ?: $stored,
                    'stored_filename' => $stored,
                    'thumbnail_filename' => $thumbnail,
                    'mime_type' => $this->mimeFor($bytes),
                    'file_size' => strlen($bytes),
                    'sha256_hash' => hash('sha256', $bytes),
                    'storage_disk' => self::DISK,
                    'storage_path' => $stored,
                    'photo_type' => PhotoType::KK_PHOTO->value,
                    'is_active' => true,
                    'uploaded_by' => $operatorId,
                    'uploaded_at' => now(),
                    'ocr_job_id' => null,
                ]);

                /*
                 * Foto lama diarsipkan (is_active = false), file fisik
                 * dan record tetap tersimpan sebagai riwayat.
                 */
                foreach ($oldPhotos as $oldPhoto) {
                    $oldPhoto->update(['is_active' => false]);
                }

                return $photo;
            });
        } catch (\Throwable $e) {
            if ($disk->exists($stored)) {
                $disk->delete($stored);
            }

            $thumbPath = pathinfo($stored, PATHINFO_FILENAME).'.thumb.png';

            if ($disk->exists($thumbPath)) {
                $disk->delete($thumbPath);
            }

            throw $e;
        }
    }

    /**
     * Hapus seluruh file dan record foto KK.
     */
    public function deleteForKk(int $kkId): void
    {
        $photos = KkPhoto::query()
            ->where('kk_id', $kkId)
            ->get();

        foreach ($photos as $photo) {
            $this->deletePhoto($photo);
        }
    }

    /**
     * Hapus file fisik + record database satu foto.
     */
    public function deletePhoto(KkPhoto $photo): void
    {
        $diskName = $photo->storage_disk ?: self::DISK;
        $disk = $this->filesystem->disk($diskName);

        $paths = array_filter([
            $photo->storage_path,
            $photo->stored_filename,
            $photo->thumbnail_filename,
        ]);

        foreach (array_unique($paths) as $path) {
            if ($disk->exists($path)) {
                $disk->delete($path);
            }
        }

        $photo->delete();
    }

    /**
     * Hapus foto KK ketika record KK dihapus.
     */
    public function deleteForDeletedKk(KartuKeluarga $kk): void
    {
        $this->deleteForKk($kk->id);
    }

    private function writeThumbnail(
        FilesystemAdapter $disk,
        string $bytes,
        string $baseName,
    ): ?string {
        if (! extension_loaded('gd')) {
            return null;
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width <= self::THUMBNAIL_MAX_WIDTH) {
            imagedestroy($image);

            return null;
        }

        $targetWidth = self::THUMBNAIL_MAX_WIDTH;
        $targetHeight = (int) round(
            $height * $targetWidth / $width,
        );

        $thumbnail = imagecreatetruecolor(
            $targetWidth,
            $targetHeight,
        );

        imagecopyresampled(
            $thumbnail,
            $image,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height,
        );

        imagedestroy($image);

        ob_start();

        imagepng($thumbnail);

        $png = ob_get_clean();

        imagedestroy($thumbnail);

        if ($png === false) {
            return null;
        }

        $name = pathinfo(
            $baseName,
            PATHINFO_FILENAME,
        ).'.thumb.png';

        $disk->put($name, $png);

        return $name;
    }

    private function extensionFor(
        ?string $original,
        string $bytes,
    ): string {
        if ($original !== null) {
            $extension = strtolower(
                (string) pathinfo(
                    $original,
                    PATHINFO_EXTENSION,
                ),
            );

            if (in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
                return $extension === 'jpeg'
                    ? 'jpg'
                    : $extension;
            }
        }

        return str_starts_with(
            $bytes,
            self::PNG_SIGNATURE,
        )
            ? 'png'
            : 'jpg';
    }

    private function mimeFor(string $bytes): string
    {
        if (str_starts_with($bytes, self::PNG_SIGNATURE)) {
            return 'image/png';
        }

        if (str_starts_with($bytes, self::JPEG_SIGNATURE)) {
            return 'image/jpeg';
        }

        return 'application/octet-stream';
    }
}
