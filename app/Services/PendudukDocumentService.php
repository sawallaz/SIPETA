<?php

namespace App\Services;

use App\Models\Penduduk;
use App\Models\PendudukDocument;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;

class PendudukDocumentService
{
    public const DISK = 'kk_uploads';

    private const ALLOWED_TYPES = [
        'KTP',
        'AKTA_KELAHIRAN',
    ];

    private const ALLOWED_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'pdf',
    ];

    public function __construct(
        private readonly FilesystemManager $filesystem,
    ) {}

    /**
     * Simpan dokumen penduduk baru.
     *
     * Dokumen lama dengan tipe yang sama tidak dihapus.
     * Dokumen lama menjadi arsip:
     *
     * is_active = false
     */
    public function store(
        Penduduk $penduduk,
        TemporaryUploadedFile $file,
        string $documentType,
        ?int $operatorId = null,
    ): PendudukDocument {
        $documentType = strtoupper(trim($documentType));

        if (! in_array(
            $documentType,
            self::ALLOWED_TYPES,
            true,
        )) {
            throw new RuntimeException(
                "Jenis dokumen '{$documentType}' tidak didukung.",
            );
        }

        $bytes = $file->get();

        if ($bytes === null || $bytes === '') {
            throw new RuntimeException(
                'File dokumen kosong atau tidak dapat dibaca.',
            );
        }

        $originalFilename = $file->getClientOriginalName();

        if (
            ! is_string($originalFilename)
            || $originalFilename === ''
            || ! mb_check_encoding(
                $originalFilename,
                'UTF-8',
            )
        ) {
            $originalFilename = null;
        }

        $extension = $this->resolveExtension(
            $originalFilename,
            $file->getMimeType(),
        );

        if ($extension === null) {
            throw new RuntimeException(
                'Format dokumen tidak didukung. Gunakan JPG, PNG, atau PDF.',
            );
        }

        $stored = Str::uuid().'.'.$extension;

        $disk = $this->filesystem->disk(self::DISK);

        $directory = 'penduduk-documents';

        $path = $directory.'/'.$stored;

        /*
         * Simpan file baru terlebih dahulu.
         */
        $disk->put($path, $bytes);

        try {
            return DB::transaction(
                function () use (
                    $penduduk,
                    $documentType,
                    $originalFilename,
                    $stored,
                    $path,
                    $bytes,
                    $extension,
                    $operatorId,
                ): PendudukDocument {
                    /*
                     * Dokumen aktif dengan tipe sama
                     * dijadikan histori.
                     */
                    PendudukDocument::query()
                        ->where(
                            'penduduk_id',
                            $penduduk->id,
                        )
                        ->where(
                            'document_type',
                            $documentType,
                        )
                        ->where(
                            'is_active',
                            true,
                        )
                        ->update([
                            'is_active' => false,
                        ]);

                    return PendudukDocument::create([
                        'penduduk_id' => $penduduk->id,

                        'document_type' => $documentType,

                        'original_filename' => $originalFilename ?: $stored,

                        'stored_filename' => $stored,

                        'mime_type' => $this->detectMimeType(
                            $bytes,
                            $extension,
                        ),

                        'file_size' => strlen($bytes),

                        'sha256_hash' => hash('sha256', $bytes),

                        'storage_disk' => self::DISK,

                        'storage_path' => $path,

                        'is_active' => true,

                        'uploaded_by' => $operatorId,

                        'uploaded_at' => now(),
                    ]);
                },
            );
        } catch (\Throwable $e) {
            if ($disk->exists($path)) {
                $disk->delete($path);
            }

            throw $e;
        }
    }

    /**
     * Hapus seluruh histori dokumen milik penduduk.
     *
     * Dipakai ketika record Penduduk benar-benar dihapus.
     */
    public function deleteForPenduduk(
        Penduduk $penduduk,
    ): void {
        $documents = PendudukDocument::query()
            ->where(
                'penduduk_id',
                $penduduk->id,
            )
            ->get();

        foreach ($documents as $document) {
            $this->delete($document);
        }
    }

    /**
     * Hapus satu dokumen beserta file fisiknya.
     */
    public function delete(
        PendudukDocument $document,
    ): void {
        $disk = $this->filesystem->disk(
            $document->storage_disk ?: self::DISK,
        );

        if (
            filled($document->storage_path)
            && $disk->exists(
                $document->storage_path,
            )
        ) {
            $disk->delete(
                $document->storage_path,
            );
        }

        $document->delete();
    }

    /**
     * Kembalikan dokumen aktif berdasarkan tipe.
     */
    public function activeDocument(
        Penduduk $penduduk,
        string $documentType,
    ): ?PendudukDocument {
        return PendudukDocument::query()
            ->where(
                'penduduk_id',
                $penduduk->id,
            )
            ->where(
                'document_type',
                strtoupper(trim($documentType)),
            )
            ->where(
                'is_active',
                true,
            )
            ->latest('id')
            ->first();
    }

    private function resolveExtension(
        ?string $originalFilename,
        ?string $mimeType,
    ): ?string {
        $extension = null;

        if ($originalFilename !== null) {
            $extension = strtolower(
                (string) pathinfo(
                    $originalFilename,
                    PATHINFO_EXTENSION,
                ),
            );
        }

        if (
            $extension !== null
            && in_array(
                $extension,
                self::ALLOWED_EXTENSIONS,
                true,
            )
        ) {
            return $extension === 'jpeg'
                ? 'jpg'
                : $extension;
        }

        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
            default => null,
        };
    }

    private function detectMimeType(
        string $bytes,
        string $extension,
    ): string {
        if (
            str_starts_with(
                $bytes,
                "\xFF\xD8\xFF",
            )
        ) {
            return 'image/jpeg';
        }

        if (
            str_starts_with(
                $bytes,
                "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A",
            )
        ) {
            return 'image/png';
        }

        if (
            str_starts_with(
                $bytes,
                '%PDF-',
            )
        ) {
            return 'application/pdf';
        }

        return match ($extension) {
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }
}
