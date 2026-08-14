<?php

namespace App\Filament\Resources\Penduduks\Pages\Concerns;

use App\Models\Penduduk;
use App\Services\PendudukDocumentService;
use App\Services\PendudukKkService;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Jembatan antara PendudukKkService dan halaman Filament.
 *
 * PendudukKkService menangani:
 * - Penduduk
 * - NIK
 * - KK
 * - histori perpindahan KK
 *
 * Trait ini menangani:
 * - mapping validation error ke state path Filament
 * - penyimpanan dokumen pendukung Penduduk
 *
 * Dokumen:
 * - KTP
 * - AKTA_KELAHIRAN
 *
 * Kedua dokumen bersifat opsional.
 *
 * @mixin HasSchemas
 */
trait SavesPendudukThroughKkService
{
    /**
     * Simpan Penduduk melalui PendudukKkService.
     *
     * File dokumen tidak dikirim ke PendudukKkService
     * karena file bukan kolom tabel penduduk.
     *
     * @param  array<string, mixed>  $data
     */
    protected function savePendudukThroughService(
        array $data,
        ?Penduduk $existing = null,
    ): Penduduk {
        /*
         * Ambil file dari state form terlebih dahulu.
         *
         * Filament FileUpload dapat mengembalikan:
         * - TemporaryUploadedFile
         * - array yang berisi TemporaryUploadedFile
         * - null
         */
        $ktpDocument = $this->resolveUploadedDocument(
            $data['ktp_document'] ?? null,
        );

        $aktaKelahiranDocument = $this->resolveUploadedDocument(
            $data['akta_kelahiran_document'] ?? null,
        );

        /*
         * File bukan field database Penduduk.
         *
         * Jangan sampai dikirim ke PendudukKkService.
         */
        unset(
            $data['ktp_document'],
            $data['akta_kelahiran_document'],
        );

        /*
         * ============================================================
         * 1. SIMPAN DATA PENDUDUK
         * ============================================================
         */
        try {
            $penduduk = app(PendudukKkService::class)->save(
                $data,
                $existing,
            );
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                $this->prefixPendudukValidationKeys($exception),
            );
        }

        /*
         * ============================================================
         * 2. SIMPAN DOKUMEN KTP
         * ============================================================
         */
        if ($ktpDocument !== null) {
            app(PendudukDocumentService::class)->store(
                penduduk: $penduduk,
                file: $ktpDocument,
                documentType: 'KTP',
                operatorId: auth()->id(),
            );
        }

        /*
         * ============================================================
         * 3. SIMPAN AKTA KELAHIRAN
         * ============================================================
         */
        if ($aktaKelahiranDocument !== null) {
            app(PendudukDocumentService::class)->store(
                penduduk: $penduduk,
                file: $aktaKelahiranDocument,
                documentType: 'AKTA_KELAHIRAN',
                operatorId: auth()->id(),
            );
        }

        /*
         * Refresh supaya relasi documents terbaru tersedia.
         */
        return $penduduk->fresh([
            'kartuKeluarga',
            'documents',
        ]);
    }

    /**
     * Normalisasi state FileUpload menjadi TemporaryUploadedFile.
     *
     * Filament dapat memberikan state sebagai object langsung
     * atau array yang berisi object.
     */
    protected function resolveUploadedDocument(
        mixed $value,
    ): ?TemporaryUploadedFile {
        if ($value instanceof TemporaryUploadedFile) {
            return $value;
        }

        if (! is_array($value)) {
            return null;
        }

        foreach ($value as $item) {
            if ($item instanceof TemporaryUploadedFile) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Mapping validation error dari domain service
     * ke state path form Filament.
     */
    protected function prefixPendudukValidationKeys(
        ValidationException $exception,
    ): array {
        $prefix = $this
            ->getSchema('form')
            ?->getStatePath();

        $prefixed = [];

        foreach ($exception->errors() as $key => $messages) {
            $prefixed[
                blank($prefix)
                || str_starts_with($key, $prefix.'.')
                    ? $key
                    : $prefix.'.'.$key
            ] = $messages;
        }

        return $prefixed;
    }
}
