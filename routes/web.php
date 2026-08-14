<?php

use App\Http\Controllers\PendudukExportController;
use App\Models\KkPhoto;
use App\Models\Penduduk;
use App\Models\PendudukDocument;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::redirect('/', '/admin');

/*
 * Authenticated KK-photo thumbnail endpoint (Phase UI-3).
 *
 * KK photos live on the private `kk_uploads` disk (never the public
 * webroot — .ai/ocr.md §10), so thumbnails are streamed through this
 * admin-only route for the KK list / edit views. Only the active photo
 * of a household is served.
 */
Route::middleware(['web', 'auth'])->prefix('admin')->group(function (): void {
    Route::get('kk-photos/{photo}/thumbnail', function (KkPhoto $photo) {
        abort_unless($photo->is_active, 404);

        $disk = Storage::disk($photo->storage_disk);
        $path = $photo->thumbnail_filename ?? $photo->storage_path;

        abort_unless($disk->exists($path), 404);

        return response($disk->get($path), 200, [
            'Content-Type' => $disk->mimeType($path) ?: 'image/png',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    })->name('kk-photos.thumbnail');

    /*
     * Full-resolution KK photo endpoint (Phase UI-5).
     *
     * The KK photo is a PRIMARY document of the household record, not merely
     * an OCR input, so the operator must be able to preview / print the
     * original image from the Detail, Edit and table views. Served from the
     * private `kk_uploads` disk (never the public webroot — .ai/ocr.md §10),
     * admin-only, for the active photo of a household.
     */
    Route::get('kk-photos/{photo}/full', function (KkPhoto $photo) {
        abort_unless($photo->is_active, 404);

        $disk = Storage::disk($photo->storage_disk);
        $path = $photo->storage_path;

        abort_unless($disk->exists($path), 404);

        return response($disk->get($path), 200, [
            'Content-Type' => $disk->mimeType($path) ?: $photo->mime_type ?: 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    })->name('kk-photos.full');
});

/*
 * Export PDF data penduduk (Phase 6.1 — Reporting & Export).
 *
 * Dipisah dari Livewire action agar response PDF diunduh dengan benar
 * oleh browser. Filter aktif dikirim sebagai query string agar PDF
 * mencerminkan tabel yang sedang difilter.
 */
Route::middleware('auth')->group(function (): void {
    Route::get(
        '/penduduk/export/pdf',
        [PendudukExportController::class, 'pdf']
    )->name('penduduk.export.pdf');

    /*
     * Preview dokumen penduduk (KTP, Akta Kelahiran).
     *
     * Dokumen penduduk disimpan di disk `kk_uploads` (private, tidak di
     * webroot), jadi preview/transmit dilakukan melalui route admin-only
     * ini. Operator yang sudah login dapat melihat dokumen melalui tabel
     * atau form edit.
     */
    Route::get('penduduk-documents/{document}/preview', function (PendudukDocument $document) {
        abort_unless($document->is_active, 404);

        $disk = Storage::disk($document->storage_disk);
        $path = $document->storage_path;

        abort_unless($disk->exists($path), 404);

        $mime = $disk->mimeType($path) ?: $document->mime_type ?: 'application/octet-stream';

        return response($disk->get($path), 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.e($document->original_filename ?? 'dokumen').'"',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    })->name('penduduk-documents.preview');
});

/*
 * Detail Penduduk (JSON) untuk modal di halaman tabel.
 */
Route::middleware(['web', 'auth'])->prefix('admin')->group(function (): void {
    Route::get('penduduk/{penduduk}/detail', function (Penduduk $penduduk) {
        $ktpDoc = $penduduk->documents()
            ->where('document_type', 'KTP')
            ->where('is_active', true)
            ->latest('id')
            ->first();

        $aktaDoc = $penduduk->documents()
            ->where('document_type', 'AKTA_KELAHIRAN')
            ->where('is_active', true)
            ->latest('id')
            ->first();

        return response()->json([
            'nik' => $penduduk->nik,
            'full_name' => $penduduk->full_name,
            'gender' => $penduduk->gender->value,
            'gender_label' => $penduduk->gender->value === 'LAKI_LAKI' ? 'Laki-laki' : 'Perempuan',
            'birth_place' => $penduduk->birth_place,
            'birth_date' => $penduduk->birth_date?->format('d M Y'),
            'age' => $penduduk->age,
            'kk_number' => $penduduk->kk_number,
            'rt' => $penduduk->rt?->number ? 'RT '.$penduduk->rt->number : '-',
            'rw' => $penduduk->rt?->areaUnit?->display_label ?? $penduduk->rt?->areaUnit?->name ?? '-',
            'address' => $penduduk->kartuKeluarga?->address ?? '-',
            'religion' => $penduduk->religion?->name ?? '-',
            'education' => $penduduk->education?->name ?? '-',
            'occupation' => $penduduk->occupation?->name ?? '-',
            'marital_status' => $penduduk->marital_status?->value ?? '-',
            'marital_status_label' => match ($penduduk->marital_status?->value ?? '') {
                'BELUM_KAWIN' => 'Belum Kawin',
                'KAWIN' => 'Kawin',
                'CERAI_HIDUP' => 'Cerai Hidup',
                'CERAI_MATI' => 'Cerai Mati',
                default => '-',
            },
            'resident_status' => $penduduk->resident_status->value,
            'resident_status_label' => match ($penduduk->resident_status->value) {
                'ACTIVE' => 'Aktif',
                'PINDAH' => 'Pindah',
                'MENINGGAL' => 'Meninggal',
                default => '-',
            },
            'notes' => $penduduk->notes ?? null,
            'documents' => [
                'ktp' => $ktpDoc ? [
                    'id' => $ktpDoc->id,
                    'url' => route('penduduk-documents.preview', $ktpDoc),
                    'mime_type' => $ktpDoc->mime_type,
                    'original_filename' => $ktpDoc->original_filename,
                ] : null,
                'akta_kelahiran' => $aktaDoc ? [
                    'id' => $aktaDoc->id,
                    'url' => route('penduduk-documents.preview', $aktaDoc),
                    'mime_type' => $aktaDoc->mime_type,
                    'original_filename' => $aktaDoc->original_filename,
                ] : null,
            ],
            'kk_photo' => $penduduk->kartuKeluarga?->active_photo_full_url ?? null,
            'edit_url' => route('admin.penduduks.edit', ['record' => $penduduk->getKey()]),
        ]);
    })->name('admin.penduduks.detail');
});
