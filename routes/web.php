<?php

use App\Exceptions\GoogleDriveException;
use App\Filament\Pages\Backup;
use App\Filament\Resources\Penduduks\PendudukResource;
use App\Http\Controllers\PendudukExportController;
use App\Http\Controllers\PendudukImportController;
use App\Http\Controllers\SetupController;
use App\Models\KkPhoto;
use App\Models\Penduduk;
use App\Models\PendudukDocument;
use App\Services\GoogleDriveClient;
use App\Services\GoogleDriveOAuthService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    $dbOk = false;
    try {
        DB::connection()->getPdo();
        $dbOk = true;
    } catch (Throwable) {
    }

    $ocrEngine = app(\App\Services\TesseractOcrEngine::class);
    $ocrPath = $ocrEngine->resolveTesseractBinary();
    $ocrAvailable = ! empty($ocrPath) && (file_exists($ocrPath) || is_executable($ocrPath));

    return response()->json([
        'status' => $dbOk ? 'ok' : 'error',
        'database' => 'sqlite',
        'ocr' => $ocrAvailable ? 'available' : 'unavailable',
    ], $dbOk ? 200 : 503);
})->name('health');

Route::get('/setup', [SetupController::class, 'show'])->name('setup');
Route::post('/setup', [SetupController::class, 'store'])->name('setup.store');

Route::redirect('/', '/admin');

Route::middleware(['web', 'auth'])->prefix('admin/backup/google')->group(function (): void {
    Route::get('connect', function (Request $request, GoogleDriveOAuthService $oauth) {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        $state = $oauth->newState();

        // Store state in Cache (15 min TTL) so callback works regardless of
        // which host / session is used — fixes 419 when Webview or LAN IP
        // causes a session mismatch with the localhost callback URI.
        Cache::put('google_oauth_state_' . $state, auth()->id(), now()->addMinutes(15));

        // Also keep session copy as fallback for same-host flow
        $request->session()->put('google_drive_oauth_state', $state);
        $request->session()->save();

        $redirectUri = (string) config('services.google_drive.redirect_uri', 'http://localhost:8100/admin/backup/google/callback');

        Log::info('Google Drive OAuth connect initiated.', [
            'host'               => $request->getHost(),
            'user_id'            => auth()->id(),
            'redirect_uri'       => $redirectUri,
            'state_hash'         => substr(hash('sha256', $state), 0, 12),
        ]);

        try {
            return redirect()->away($oauth->authorizationUrl($state, $redirectUri));
        } catch (GoogleDriveException $e) {
            Log::warning('Google Drive OAuth unavailable.', ['status' => $e->httpStatus]);

            return redirect(Backup::getUrl())->with(
                'google_drive_error',
                'Konfigurasi OAuth Google Drive belum tersedia di lingkungan ini.',
            );
        }
    })->name('google-drive.connect');

    Route::get('callback', function (Request $request, GoogleDriveOAuthService $oauth, GoogleDriveClient $drive) {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        $receivedState = (string) $request->query('state', '');

        // --- Primary: validate via Cache (cross-host safe) ---
        $cacheKey = 'google_oauth_state_' . $receivedState;
        $cachedUserId = Cache::get($cacheKey);
        $stateValidByCacheId = filled($cachedUserId) && (int) $cachedUserId === (int) auth()->id();

        // --- Fallback: validate via session (same-host flow) ---
        $expectedState = $request->session()->get('google_drive_oauth_state');
        $stateValidBySession = filled($expectedState) && hash_equals((string) $expectedState, $receivedState);

        $redirectUri = (string) config('services.google_drive.redirect_uri', 'http://localhost:8100/admin/backup/google/callback');

        Log::info('Google Drive OAuth callback received.', [
            'host'                   => $request->getHost(),
            'user_id'                => auth()->id(),
            'redirect_uri'           => $redirectUri,
            'has_code'               => $request->filled('code'),
            'has_error'              => $request->filled('error'),
            'state_valid_by_cache'   => $stateValidByCacheId,
            'state_valid_by_session' => $stateValidBySession,
        ]);

        if (! $stateValidByCacheId && ! $stateValidBySession) {
            Log::warning('Google Drive OAuth state validation failed.', [
                'host'           => $request->getHost(),
                'user_id'        => auth()->id(),
                'cached_user_id' => $cachedUserId,
            ]);
            abort(419, 'Sesi OAuth Google Drive tidak valid.');
        }

        // Clean up
        Cache::forget($cacheKey);
        $request->session()->forget(['google_drive_oauth_state', 'google_drive_oauth_redirect_uri']);
        $request->session()->save();

        if ($request->filled('error') || ! $request->filled('code')) {
            return redirect(Backup::getUrl())->with('google_drive_error', 'Otorisasi Google Drive dibatalkan.');
        }

        try {
            $credentials = $oauth->exchangeCode((string) $request->query('code'), $redirectUri);
            $oauth->storeCredentials($credentials);
            $about  = $drive->about();
            $folder = $drive->ensureBackupFolder();
            $email  = $about['user']['emailAddress'] ?? null;

            app(SettingsService::class)->saveGoogleDriveConnection(
                $credentials,
                is_string($email) ? $email : null,
                $folder['id'],
            );
            Log::info('Google Drive connected.', ['account_email' => $email]);

            return redirect(Backup::getUrl())->with('google_drive_message', 'Google Drive berhasil terhubung.');
        } catch (GoogleDriveException $e) {
            Log::warning('Google Drive connection failed (GoogleDriveException).', [
                'status' => $e->httpStatus,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'previous_message' => $e->getPrevious() ? $e->getPrevious()->getMessage() : null,
            ]);

            return redirect(Backup::getUrl())->with('google_drive_error', $e->getMessage());
        } catch (Throwable $e) {
            Log::warning('Google Drive connection failed (Throwable).', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect(Backup::getUrl())->with('google_drive_error', 'Google Drive gagal terhubung: ' . $e->getMessage());
        }
    })->name('google-drive.callback');

    Route::get('download-local', function (\App\Services\BackupService $backupService) {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        $method = new \ReflectionMethod($backupService, 'buildArchive');
        $method->setAccessible(true);
        $archive = $method->invoke($backupService);
        $filename = $backupService->filename();

        if (ob_get_level()) {
            ob_end_clean();
        }

        return response()->download($archive['path'], $filename, [
            'Content-Type' => 'application/zip',
            'Content-Length' => (string) filesize($archive['path']),
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ])->deleteFileAfterSend(true);
    })->name('admin.backup.download-local');

    Route::get('{backupLog}/download', function (\App\Models\BackupLog $backupLog, \App\Services\GoogleDriveClient $drive) {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
        abort_unless($backupLog->drive_file_id, 404);

        $tmp = tempnam(sys_get_temp_dir(), 'sipeta_drive_dl_');
        $drive->download($backupLog->drive_file_id, $tmp);

        if (ob_get_level()) {
            ob_end_clean();
        }

        return response()->download($tmp, $backupLog->filename, [
            'Content-Type' => 'application/zip',
            'Content-Length' => (string) filesize($tmp),
            'Content-Disposition' => 'attachment; filename="'.$backupLog->filename.'"',
        ])->deleteFileAfterSend(true);
    })->name('admin.backup.download');
});

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
 * Export CSV, Excel, PDF data penduduk (Phase 6.1 — Reporting & Export).
 *
 * Dipisah dari Livewire action agar response diunduh dengan benar
 * oleh browser. Filter aktif dikirim sebagai query string agar ekspor
 * mencerminkan tabel yang sedang difilter.
 */
Route::middleware('auth')->group(function (): void {
    Route::get(
        '/penduduk/export/csv',
        [PendudukExportController::class, 'csv']
    )->name('penduduk.export.csv');

    Route::get(
        '/penduduk/export/xlsx',
        [PendudukExportController::class, 'xlsx']
    )->name('penduduk.export.xlsx');

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
    // Import Penduduk dari Excel/CSV
    Route::post('penduduk/import/upload', [PendudukImportController::class, 'upload'])
        ->name('penduduk.import.upload');
    Route::post('penduduk/import/select-sheet', [PendudukImportController::class, 'selectSheet'])
        ->name('penduduk.import.select-sheet');
    Route::post('penduduk/import/map-columns', [PendudukImportController::class, 'mapColumns'])
        ->name('penduduk.import.map-columns');
    Route::post('penduduk/import/preview', [PendudukImportController::class, 'preview'])
        ->name('penduduk.import.preview');
    Route::post('penduduk/import', [PendudukImportController::class, 'import'])
        ->name('penduduk.import');
    Route::post('penduduk/import/cancel', [PendudukImportController::class, 'cancel'])
        ->name('penduduk.import.cancel');

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
            'rt' => ($penduduk->current_rt?->number ?? $penduduk->rt?->number ?? $penduduk->kartuKeluarga?->rt?->number)
                ? 'RT '.($penduduk->current_rt?->number ?? $penduduk->rt?->number ?? $penduduk->kartuKeluarga?->rt?->number)
                : '-',
            'rw' => ($penduduk->area_unit?->display_label ?? $penduduk->area_unit?->name ?? $penduduk->kartuKeluarga?->nama_wilayah)
                ?: '-',
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
            'edit_url' => PendudukResource::getUrl('edit', ['record' => $penduduk]),
        ]);
    })->name('admin.penduduks.detail');

});
