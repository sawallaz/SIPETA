<?php

use App\Models\KkPhoto;
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
