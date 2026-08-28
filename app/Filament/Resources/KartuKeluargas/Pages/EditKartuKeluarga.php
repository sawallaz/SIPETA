<?php

namespace App\Filament\Resources\KartuKeluargas\Pages;

use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\OcrJobStatus;
use App\Filament\Resources\KartuKeluargas\KartuKeluargaDeleteGuard;
use App\Filament\Resources\KartuKeluargas\KartuKeluargaResource;
use App\Filament\Resources\KartuKeluargas\Pages\Concerns\ChecksDuplicateKkNumber;
use App\Filament\Resources\KartuKeluargas\Schemas\KartuKeluargaForm;
use App\Models\KartuKeluarga;
use App\Models\OcrJob;
use App\Models\Penduduk;
use App\Models\Rt;
use App\Services\KkPhotoService;
use App\Services\OcrProcessingService;
use App\Services\ParsedOcrResult;
use App\Services\ParsedResident;
use App\Services\PendudukKkService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class EditKartuKeluarga extends EditRecord
{
    use ChecksDuplicateKkNumber;

    protected static string $resource = KartuKeluargaResource::class;

    /**
     * OCR transient review state.
     *
     * Flow:
     * 1. Upload photo -> previews in FileUpload only (Upload != Scan).
     * 2. Click "Scan OCR" -> runs OCR and opens single OCR Modal overlay.
     * 3. Modal shows scanned KK data, members list, and bounded photo preview.
     * 4. [Kembali] -> closes modal, form state untouched.
     * 5. [Gunakan Hasil OCR] -> applies scanned header + stages members into form, closes modal.
     * 6. [Simpan Perubahan] -> single save button commits KK + photo + members in 1 DB transaction.
     */
    public array $ocrPreview = [];

    /**
     * Controls whether the single OCR review modal overlay is open.
     */
    public bool $isOcrModalOpen = false;

    /**
     * Staged members from approved OCR scan to be persisted on final form save.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $pendingOcrMembers = [];

    /**
     * Prevent re-entrant Livewire updates while a scan is running.
     */
    protected bool $scanning = false;

    /**
     * Path OCR temp (on kk_uploads disk) that must be cleaned after scan.
     */
    protected ?string $ocrTempPath = null;

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function getTitle(): string
    {
        return 'Ubah Kartu Keluarga';
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Perubahan Kartu Keluarga berhasil disimpan';
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->label('Simpan Perubahan')
            ->icon('heroicon-o-check');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label('Batal')
            ->icon('heroicon-o-x-mark')
            ->color('gray');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            ...KartuKeluargaForm::components(),

            // Modal overlay OCR — renders as an overlay dialog, NOT an inline section below form
            Placeholder::make('ocr_modal_overlay')
                ->hiddenLabel()
                ->dehydrated(false)
                ->content(fn (): Htmlable => $this->renderOcrModal())
                ->columnSpanFull(),
        ]);
    }

    /**
     * Header actions for Edit KK page:
     * - Scan OCR: Trigger explicit scan and open modal
     * - Hapus: Delete KK
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('scanFotoKk')
                ->label('Scan OCR')
                ->icon('heroicon-m-camera')
                ->color('primary')
                ->action(fn () => $this->scanFoto()),

            DeleteAction::make()
                ->label('Hapus')
                ->modalHeading('Hapus Kartu Keluarga')
                ->modalDescription(function (Model $record): HtmlString {
                    /** @var KartuKeluarga $record */
                    $memberCount = $record->penduduks()->count();
                    $kepala = $record->kepalaKeluarga?->full_name ?? 'Belum ditentukan';
                    $alamat = $record->address_with_rt_rw ?? ($record->address ?? '-');

                    $warning = $memberCount > 0
                        ? '<div class="mt-3 p-3 bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300 rounded-lg text-sm font-medium border border-red-200 dark:border-red-900">⚠️ PERINGATAN: Kartu Keluarga ini masih memiliki '.$memberCount.' anggota keluarga aktif. Sesuai aturan sistem, KK yang memiliki anggota atau riwayat tidak dapat dihapus permanen untuk melindungi integritas data kependudukan.</div>'
                        : '<div class="mt-3 p-3 bg-gray-50 text-gray-700 dark:bg-gray-800 dark:text-gray-300 rounded-lg text-sm">Hapus permanen hanya dapat dilakukan untuk data KK yang benar-benar kosong dan belum memiliki riwayat kependudukan.</div>';

                    return new HtmlString('
                        <div class="space-y-2 text-sm text-left">
                            <p>Anda akan menghapus data Kartu Keluarga:</p>
                            <div class="bg-gray-100 dark:bg-gray-800 p-3 rounded-lg space-y-1">
                                <div><strong>Nomor KK:</strong> '.e($record->kk_number).'</div>
                                <div><strong>Kepala Keluarga:</strong> '.e($kepala).'</div>
                                <div><strong>Alamat:</strong> '.e($alamat).'</div>
                                <div><strong>Jumlah Anggota:</strong> '.e((string) $memberCount).' orang</div>
                            </div>
                            '.$warning.'
                        </div>
                    ');
                })
                ->modalSubmitActionLabel('Ya, Hapus')
                ->modalCancelActionLabel('Batal')
                ->before(
                    function (Model $record): void {
                        KartuKeluargaDeleteGuard::assertDeletable($record);
                    }
                )
                ->successNotificationTitle(
                    'Kartu Keluarga berhasil dihapus'
                ),
        ];
    }

    /**
     * Run OCR against uploaded or archived photo and open single review modal.
     */
    public function scanFoto(): void
    {
        $rawPath = $this->data['kk_photo'] ?? null;

        if (blank($rawPath) && $this->record?->activePhoto?->file_path) {
            $rawPath = $this->record->activePhoto->file_path;
        }

        $path = $this->resolveOcrDiskPath($rawPath);

        if ($path === null) {
            Notification::make()
                ->warning()
                ->title('Foto KK belum siap')
                ->body(
                    'Pilih atau unggah foto Kartu Keluarga terlebih dahulu, lalu tekan Scan OCR.'
                )
                ->send();

            return;
        }

        if ($this->scanning) {
            return;
        }

        $this->scanning = true;

        try {
            $parsed = $this->runOcr($path);

            $this->applyParsed($parsed);

            if ($parsed->isEmpty()) {
                Notification::make()
                    ->warning()
                    ->title('Data KK tidak terbaca')
                    ->body(
                        'Foto berhasil diproses, tetapi tidak ada data Kartu Keluarga '
                        .'yang berhasil dikenali. Periksa kualitas foto atau lengkapi data secara manual.'
                    )
                    ->send();

                return;
            }

            // Open the single OCR Modal overlay
            $this->isOcrModalOpen = true;

            if (! empty($this->ocrPreview['is_kk_conflict'])) {
                Notification::make()
                    ->danger()
                    ->title('Nomor KK sudah digunakan oleh KK lain')
                    ->body(
                        sprintf(
                            'Nomor KK %s hasil OCR sudah terdaftar pada KK lain (%s).',
                            $this->ocrPreview['kk_number'] ?? '',
                            $this->ocrPreview['conflict_kk']['kepala'] ?? 'KK Lain',
                        )
                    )
                    ->send();
            } elseif ($parsed->isValid()) {
                Notification::make()
                    ->success()
                    ->title('OCR selesai')
                    ->body('Data KK berhasil dibaca. Silakan periksa hasil pada jendela peninjauan.')
                    ->send();
            } else {
                Notification::make()
                    ->info()
                    ->title('OCR selesai')
                    ->body('Beberapa data perlu diperiksa pada jendela peninjauan.')
                    ->send();
            }
        } catch (Throwable $e) {
            Log::warning('KK edit scan failed', [
                'kk_id' => $this->record?->getKey(),
                'error' => $e->getMessage(),
                'exception' => $e::class,
                'photo_state_type' => get_debug_type($rawPath),
                'normalized_path' => $path,
            ]);

            $this->ocrPreview = [];
            $this->isOcrModalOpen = false;

            Notification::make()
                ->danger()
                ->title('Pemindaian KK gagal')
                ->body(
                    $this->ocrErrorMessage($e)
                )
                ->send();
        } finally {
            $this->scanning = false;

            $this->cleanupOcrTemp($rawPath);
        }
    }

    /**
     * Tutup modal OCR tanpa mengubah form maupun database (Tombol "Kembali").
     */
    public function closeOcrModal(): void
    {
        $this->isOcrModalOpen = false;
        $this->ocrPreview = [];
        $this->duplicateKk = [];
    }

    /**
     * Terapkan hasil OCR ke Form Edit dan tutup modal (Tombol "Gunakan Hasil OCR").
     *
     * Data header masuk ke form fields, daftar anggota masuk ke pending state,
     * lalu modal ditutup agar operator dapat meninjau/mengedit di halaman form biasa.
     */
    public function applyOcrResult(): void
    {
        if (blank($this->ocrPreview)) {
            $this->isOcrModalOpen = false;

            return;
        }

        if (! empty($this->ocrPreview['is_kk_conflict'])) {
            $conflictMsg = $this->ocrPreview['conflict_reason'] ?? 'Nomor KK atau NIK hasil OCR sudah terdaftar pada KK lain dan tidak dapat diterapkan ke formulir ini.';
            Notification::make()
                ->danger()
                ->title('⚠️ Nomor KK / NIK Sudah Terdaftar di Sistem!')
                ->body($conflictMsg)
                ->send();

            return;
        }

        $data = $this->data ?? [];

        if (filled($this->ocrPreview['kk_number'] ?? null)) {
            $data['kk_number'] = $this->ocrPreview['kk_number'];
        }

        if (filled($this->ocrPreview['address'] ?? null)) {
            $data['address'] = $this->ocrPreview['address'];
        }

        if (filled($this->ocrPreview['postal_code'] ?? null)) {
            $data['postal_code'] = $this->ocrPreview['postal_code'];
        }

        if (blank($data['rt_id'] ?? null) && filled($this->ocrPreview['rt'] ?? null)) {
            $rt = $this->resolveRt($this->ocrPreview['rt']);

            if ($rt !== null) {
                $data['area_unit_id'] = $rt->area_unit_id;
                $data['rt_id'] = $rt->id;
            }
        }

        // Simpan anggota OCR langsung ke database jika ada
        if (! empty($this->ocrPreview['members'])) {
            try {
                app(PendudukKkService::class)->saveOcrMembers(
                    $this->record,
                    $this->ocrPreview['members'],
                );
            } catch (Throwable $e) {
                Log::warning('Failed saving OCR members during applyOcrResult: '.$e->getMessage());
            }
        }

        $this->record->refresh();

        // Isi form edit KK
        $this->form->fill($data);
        $this->data = $data;

        // Tutup modal
        $this->isOcrModalOpen = false;
        $this->ocrPreview = [];
        $this->duplicateKk = [];

        $this->dispatch('$refresh');

        Notification::make()
            ->success()
            ->title('Data hasil OCR berhasil diterapkan')
            ->body('Data Kartu Keluarga dan daftar anggota telah diperbarui sesuai hasil OCR.')
            ->send();
    }

    /**
     * Save atomic: commits KK fields + uploaded photo + staged OCR members.
     *
     * Exactly ONE save button on the entire Edit KK page: "Simpan Perubahan".
     */
    protected function handleRecordUpdate(
        Model $record,
        array $data,
    ): Model {
        return DB::transaction(function () use ($record, $data): Model {
            $photo = $data['kk_photo'] ?? null;

            unset($data['kk_photo']);

            $record->update($data);

            if ($photo instanceof TemporaryUploadedFile) {
                app(KkPhotoService::class)->storeUploadedFileForKk(
                    $record->id,
                    $photo,
                    auth()->id(),
                );
            }

            // Simpan anggota OCR jika ada yang disetujui
            if (! empty($this->pendingOcrMembers)) {
                app(PendudukKkService::class)->saveOcrMembers(
                    $record,
                    $this->pendingOcrMembers,
                );

                $this->pendingOcrMembers = [];
            }

            return $record->fresh();
        });
    }

    /**
     * Normalisasi nilai FileUpload menjadi satu path foto.
     */
    private function normalizePhotoPath(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value !== '' ? $value : null;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $path = $this->normalizePhotoPath($item);

                if ($path !== null) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * Resolusi path foto untuk OCR.
     */
    private function resolveOcrDiskPath(mixed $value): ?string
    {
        if ($value instanceof TemporaryUploadedFile) {
            return $this->storeOcrTemporaryFile($value);
        }

        if (is_array($value)) {
            foreach ($value as $uploadedFile) {
                $resolved = $this->resolveOcrDiskPath($uploadedFile);
                if ($resolved !== null) {
                    return $resolved;
                }
            }

            return null;
        }

        if (is_string($value) && trim($value) !== '') {
            $path = trim($value);

            if (Storage::disk(KkPhotoService::DISK)->exists($path)) {
                return $path;
            }

            $cleaned = preg_replace('/^livewire-file:/', '', $path);
            $candidates = [
                $path,
                $cleaned,
                'livewire-tmp/'.$cleaned,
                'private/livewire-tmp/'.$cleaned,
                storage_path('app/'.$path),
                storage_path('app/'.$cleaned),
                storage_path('app/livewire-tmp/'.$cleaned),
                storage_path('app/private/livewire-tmp/'.$cleaned),
                storage_path('app/kk_uploads/'.$path),
            ];

            foreach ($candidates as $candidate) {
                if (is_file($candidate) && is_readable($candidate)) {
                    $bytes = file_get_contents($candidate);
                    if ($bytes !== false && $bytes !== '') {
                        $ext = pathinfo($candidate, PATHINFO_EXTENSION) ?: 'jpg';
                        $tempPath = 'ocr-tmp/'.Str::uuid().'.'.$ext;
                        Storage::disk(KkPhotoService::DISK)->put($tempPath, $bytes);
                        $this->ocrTempPath = $tempPath;

                        return $tempPath;
                    }
                } elseif (Storage::disk('local')->exists($candidate)) {
                    $bytes = Storage::disk('local')->get($candidate);
                    if ($bytes !== null && $bytes !== '') {
                        $ext = pathinfo($candidate, PATHINFO_EXTENSION) ?: 'jpg';
                        $tempPath = 'ocr-tmp/'.Str::uuid().'.'.$ext;
                        Storage::disk(KkPhotoService::DISK)->put($tempPath, $bytes);
                        $this->ocrTempPath = $tempPath;

                        return $tempPath;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Salin byte TemporaryUploadedFile ke disk kk_uploads/ocr-tmp.
     */
    private function storeOcrTemporaryFile(
        TemporaryUploadedFile $file
    ): ?string {
        $bytes = $file->get();

        if ($bytes === null || $bytes === '') {
            return null;
        }

        $extension = 'jpg';

        if (
            preg_match(
                '/\.(png|jpe?g)$/i',
                (string) $file->getClientOriginalName(),
                $match,
            )
        ) {
            $extension = strtolower($match[1]) === 'jpeg'
                ? 'jpg'
                : 'png';
        }

        $path = 'ocr-tmp/'.Str::uuid().'.'.$extension;

        Storage::disk(KkPhotoService::DISK)->put(
            $path,
            $bytes,
        );

        $this->ocrTempPath = $path;

        return $path;
    }

    /**
     * Hapus file OCR temp di disk kk_uploads.
     */
    private function cleanupOcrTemp(mixed $rawValue): void
    {
        if (
            $rawValue instanceof TemporaryUploadedFile
            && $this->ocrTempPath !== null
            && Storage::disk(KkPhotoService::DISK)->exists($this->ocrTempPath)
        ) {
            Storage::disk(KkPhotoService::DISK)->delete($this->ocrTempPath);
        }

        $this->ocrTempPath = null;
    }

    /**
     * Pesan error yang dibedakan berdasarkan jenis kegagalan.
     */
    private function ocrErrorMessage(Throwable $e): string
    {
        $message = strtolower($e->getMessage());

        if (
            str_contains($message, 'array to string conversion')
            || str_contains($message, 'array given')
        ) {
            return 'Format data foto belum siap diproses. '
                .'Tunggu upload selesai, kemudian tekan Scan OCR kembali.';
        }

        if (
            str_contains($message, 'could not be decoded')
            || str_contains($message, 'image could not be decoded')
        ) {
            return 'File foto tidak dapat dibaca sebagai gambar. '
                .'Gunakan format JPG atau PNG yang valid.';
        }

        if (
            str_contains($message, 'resolution below minimum')
        ) {
            return 'Resolusi foto terlalu rendah untuk OCR. '
                .'Gunakan foto KK yang lebih jelas (minimal 800x600 px).';
        }

        if (
            str_contains($message, 'source image')
            || str_contains($message, 'file does not exist')
            || str_contains($message, 'unable to load')
            || str_contains($message, 'tidak ditemukan')
        ) {
            return 'File foto KK tidak ditemukan atau belum selesai disimpan. '
                .'Silakan upload ulang foto KK.';
        }

        if (
            str_contains($message, 'not recognized')
            || str_contains($message, 'tesseract failed')
            || str_contains($message, 'tesseract exited')
            || str_contains($message, 'cannot find')
            || str_contains($message, 'no such file')
        ) {
            return 'Komponen OCR (Tesseract) tidak tersedia atau gagal dijalankan. '
                .'Pastikan Tesseract terinstal pada sistem. Data dapat diisi secara manual.';
        }

        if (
            str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
        ) {
            return 'Waktu proses OCR habis (timeout). '
                .'Coba kembali atau isi formulir secara manual.';
        }

        return 'Foto berhasil diterima, tetapi proses OCR gagal: ' . $e->getMessage() . '. '
            .'Periksa kualitas foto atau isi formulir secara manual.';
    }

    /**
     * Run OCR pipeline.
     */
    private function runOcr(string $path): ParsedOcrResult
    {
        $disk = Storage::disk(KkPhotoService::DISK);

        if (! $disk->exists($path)) {
            throw new \RuntimeException(
                'File foto KK tidak ditemukan pada penyimpanan.'
            );
        }

        $contents = $disk->get($path);

        if ($contents === '') {
            throw new \RuntimeException(
                'File foto KK kosong dan tidak dapat diproses.'
            );
        }

        $job = OcrJob::create([
            'kk_id' => $this->record?->getKey(),
            'source_image_hash' => hash('sha256', $contents),
            'source_image_path' => $path,
            'status' => OcrJobStatus::PENDING,
            'operator_id' => auth()->id(),
            'started_at' => now(),
        ]);

        $processing = app(OcrProcessingService::class);

        $processing->start($job);
        $processing->extract($job);

        return $processing->parse($job);
    }

    /**
     * Build transient OCR preview state without mutating form data or database.
     */
    private function applyParsed(ParsedOcrResult $parsed): void
    {
        $isKkConflict = false;
        $conflictKkData = null;
        $conflictReason = null;

        // 1. Check if parsed KK number belongs to another KK in database
        if (
            filled($parsed->kkNumber)
            && $parsed->kkNumber !== $this->record?->kk_number
        ) {
            $cleanNumber = preg_replace('/\D/', '', (string) $parsed->kkNumber);
            if (strlen($cleanNumber) === 16) {
                $conflictRecord = KartuKeluarga::query()
                    ->with(['rt.areaUnit'])
                    ->where('kk_number', $cleanNumber)
                    ->whereKeyNot($this->record?->getKey())
                    ->first();

                if ($conflictRecord !== null) {
                    $isKkConflict = true;
                    $conflictKkData = [
                        'id' => $conflictRecord->getKey(),
                        'number' => (string) $conflictRecord->kk_number,
                        'kepala' => $conflictRecord->kepalaKeluarga?->full_name ?? 'Belum ditentukan',
                        'address' => (string) ($conflictRecord->address ?? '-'),
                        'rt' => $conflictRecord->nomor_rt ? 'RT '.$conflictRecord->nomor_rt : '-',
                        'rw' => (string) ($conflictRecord->nama_wilayah ?? '-'),
                        'member_count' => $conflictRecord->jumlah_anggota.' orang',
                        'view_url' => KartuKeluargaResource::getUrl('view', ['record' => $conflictRecord]),
                        'edit_url' => KartuKeluargaResource::getUrl('edit', ['record' => $conflictRecord]),
                    ];
                    $conflictReason = 'Nomor KK '.$conflictRecord->kk_number.' atas nama '.($conflictRecord->kepalaKeluarga?->full_name ?? 'Belum ditentukan').' sudah ada di database (ID: #'.$conflictRecord->id.').';
                    $this->duplicateKk = $conflictKkData;
                }
            }
        }

        // 2. Check if any member's NIK belongs to another KK in database
        $parsedNiks = [];
        foreach ($parsed->members as $member) {
            $nik = preg_replace('/\D/', '', (string) ($member->nik ?? ''));
            if (strlen($nik) === 16) {
                $parsedNiks[] = $nik;
            }
        }

        if (! empty($parsedNiks)) {
            $conflictResident = Penduduk::query()
                ->with(['kartuKeluarga.rt.areaUnit'])
                ->whereIn('nik', $parsedNiks)
                ->where('kk_id', '!=', $this->record?->getKey())
                ->whereNotNull('kk_id')
                ->first();

            if ($conflictResident !== null && $conflictResident->kartuKeluarga !== null) {
                $otherKk = $conflictResident->kartuKeluarga;
                $isKkConflict = true;
                $conflictKkData = [
                    'id' => $otherKk->getKey(),
                    'number' => (string) $otherKk->kk_number,
                    'kepala' => $otherKk->kepalaKeluarga?->full_name ?? 'Belum ditentukan',
                    'address' => (string) ($otherKk->address ?? '-'),
                    'rt' => $otherKk->nomor_rt ? 'RT '.$otherKk->nomor_rt : '-',
                    'rw' => (string) ($otherKk->nama_wilayah ?? '-'),
                    'member_count' => $otherKk->jumlah_anggota.' orang',
                    'view_url' => KartuKeluargaResource::getUrl('view', ['record' => $otherKk]),
                    'edit_url' => KartuKeluargaResource::getUrl('edit', ['record' => $otherKk]),
                ];
                $conflictReason = 'NIK '.$conflictResident->nik.' ('.$conflictResident->full_name.') sudah terdaftar pada KK lain (No KK: '.$otherKk->kk_number.', Kepala: '.($otherKk->kepalaKeluarga?->full_name ?? 'Belum ditentukan').', ID: #'.$otherKk->id.').';
                $this->duplicateKk = $conflictKkData;
            }
        }

        // Build member list with resident lookup
        $members = [];
        foreach ($parsed->members as $member) {
            $memberData = $this->memberFromParsed($member);

            if (
                ! empty($memberData['nik'])
                && preg_match('/^\d{16}$/', $memberData['nik'])
            ) {
                $existingResident = Penduduk::query()
                    ->with(['kartuKeluarga'])
                    ->where('nik', $memberData['nik'])
                    ->first();

                if ($existingResident !== null) {
                    $memberData['existing_resident'] = [
                        'id' => $existingResident->id,
                        'full_name' => $existingResident->full_name,
                        'current_kk' => $existingResident->kartuKeluarga?->kk_number ?? '-',
                        'status' => $existingResident->resident_status?->value ?? 'ACTIVE',
                    ];
                }
            }

            $members[] = $memberData;
        }

        $this->ocrPreview = [
            'kk_number' => $parsed->kkNumber,
            'address' => $parsed->address,
            'postal_code' => $parsed->postalCode,
            'rt' => $parsed->rt,
            'is_kk_conflict' => $isKkConflict,
            'conflict_kk' => $conflictKkData,
            'conflict_reason' => $conflictReason,
            'members' => $members,
            'confidence' => $parsed->confidence,
            'validation_errors' => $parsed->validationErrors,
            'warnings' => $parsed->warnings,
        ];
    }

    /**
     * Convert parsed OCR resident into review array.
     */
    private function memberFromParsed(ParsedResident $member): array
    {
        return [
            'full_name' => $member->nama ?? '',
            'nama' => $member->nama ?? '',
            'nik' => $member->nik ?? '',
            'gender' => $this->genderLabel($member->gender),
            'birth_place' => $member->birthPlace ?? '',
            'birth_date' => $member->birthDate ?? '',
            'religion' => $member->religion ?? '',
            'education' => $member->education ?? '',
            'occupation' => $member->occupation ?? '',
            'marital_status' => $this->maritalLabel($member->maritalStatus),
            'family_relation' => $this->relationLabel($member->familyRelation),
            'confidence' => $member->confidence,
            'low_confidence' => $member->lowConfidence,
        ];
    }

    private function genderLabel(?string $value): string
    {
        return match ($value) {
            Gender::LAKI_LAKI->value, 'L', 'LAKI-LAKI' => 'Laki-laki',
            Gender::PEREMPUAN->value, 'P', 'PEREMPUAN' => 'Perempuan',
            default => $value ?: '',
        };
    }

    private function maritalLabel(?string $value): string
    {
        return match ($value) {
            MaritalStatus::BELUM_KAWIN->value, 'BELUM KAWIN' => 'Belum Kawin',
            MaritalStatus::KAWIN->value, 'KAWIN' => 'Kawin',
            MaritalStatus::CERAI_HIDUP->value, 'CERAI HIDUP' => 'Cerai Hidup',
            MaritalStatus::CERAI_MATI->value, 'CERAI MATI' => 'Cerai Mati',
            default => $value ?: '',
        };
    }

    private function relationLabel(?string $value): string
    {
        if ($value === null || trim($value) === '' || $value === '-') {
            return '';
        }

        $upper = strtoupper(trim($value));

        return match ($upper) {
            'KEPALA_KELUARGA', 'KEPALA KELUARGA', 'KEPALA KEL.', 'KEPALA KEL', 'KEPALAKELUARGA', 'KEPALAKEUARGA', 'KEPALA' => 'Kepala Keluarga',
            'ISTRI', 'ISTERI', '1STRI', 'ISTRI KEPALA KELUARGA' => 'Istri',
            'ANAK', 'ANAK2', 'ANAK-', 'AN4K', 'ANAK KANDUNG', 'ANAK ANGKAT', 'ANAK TIRI' => 'Anak',
            'MENANTU' => 'Menantu',
            'CUCU' => 'Cucu',
            'ORANG_TUA', 'ORANG TUA', 'ORANGTUA', 'BAPAK', 'IBU', 'AYAH' => 'Orang Tua',
            'MERTUA' => 'Mertua',
            'FAMILI_LAIN', 'FAMILI LAIN', 'FAMILI LAINNYA', 'FAMILI', 'FAMILILAIN' => 'Famili Lain',
            'PEMBANTU', 'LAINNYA', 'LAIN' => 'Lainnya',
            default => match (FamilyRelation::tryFrom($upper)) {
                FamilyRelation::KEPALA_KELUARGA => 'Kepala Keluarga',
                FamilyRelation::ISTRI => 'Istri',
                FamilyRelation::ANAK => 'Anak',
                FamilyRelation::MENANTU => 'Menantu',
                FamilyRelation::CUCU => 'Cucu',
                FamilyRelation::ORANG_TUA => 'Orang Tua',
                FamilyRelation::MERTUA => 'Mertua',
                FamilyRelation::FAMILI_LAIN => 'Famili Lain',
                FamilyRelation::LAINNYA => 'Lainnya',
                default => ucwords(strtolower(str_replace('_', ' ', $value))),
            },
        };
    }

    /**
     * Resolve RT from OCR text.
     */
    private function resolveRt(?string $number): ?Rt
    {
        $number = trim((string) $number);

        if ($number === '') {
            return null;
        }

        $normalized = ltrim($number, '0');

        if ($normalized === '') {
            $normalized = '0';
        }

        return Rt::query()
            ->where(function ($query) use ($number, $normalized): void {
                $query
                    ->where('number', $number)
                    ->orWhere('number', str_pad($normalized, 2, '0', STR_PAD_LEFT))
                    ->orWhere('number', str_pad($normalized, 3, '0', STR_PAD_LEFT));
            })
            ->first();
    }

    /**
     * Render the single OCR Modal overlay.
     */
    private function renderOcrModal(): Htmlable
    {
        $preview = $this->ocrPreview;
        $members = $preview['members'] ?? [];
        $isConflict = ! empty($preview['is_kk_conflict']);
        $conflictKk = $preview['conflict_kk'] ?? null;

        $html = <<<'HTML'
<div
    x-data
    x-show="$wire.isOcrModalOpen"
    x-cloak
    x-transition.opacity.duration.200ms
    @keydown.escape.window="$wire.closeOcrModal()"
    class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs overflow-y-auto"
    style="display: none;"
>
    <!-- Modal panel (max-w-5xl, rounded-2xl, bounded height, scrollable internal body) -->
    <div
        class="relative w-full max-w-5xl max-h-[90vh] flex flex-col rounded-2xl bg-white dark:bg-gray-900 shadow-2xl border border-gray-200 dark:border-gray-800 overflow-hidden"
        @click.away="$wire.closeOcrModal()"
    >
        <!-- Modal Header -->
        <div class="flex items-center justify-between border-b border-gray-200 dark:border-gray-800 px-6 py-4 bg-gray-50 dark:bg-gray-800/60">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </div>
                <div>
                    <h2 class="text-base font-bold text-gray-900 dark:text-gray-100">Hasil Pemindaian OCR</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Tinjau data hasil OCR sebelum menerapkannya ke formulir Kartu Keluarga.</p>
                </div>
            </div>
            <button
                type="button"
                wire:click="closeOcrModal"
                class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700 hover:text-gray-700 dark:hover:text-gray-200 transition"
            >
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <!-- Modal Body (Scrollable) -->
        <div class="flex-1 overflow-y-auto p-6 space-y-5">
HTML;

        $isEmpty = empty($members) && blank($preview['kk_number'] ?? null);
        $hasIncompleteFields = false;

        if (blank($preview['kk_number'] ?? null) || blank($preview['address'] ?? null) || blank($preview['postal_code'] ?? null) || blank($preview['rt'] ?? null)) {
            $hasIncompleteFields = true;
        }

        foreach ($members as $m) {
            if (
                blank($m['full_name'] ?? null)
                || blank($m['nik'] ?? null)
                || strlen($m['nik'] ?? '') !== 16
                || blank($m['gender'] ?? null)
                || blank($m['birth_date'] ?? null)
                || blank($m['religion'] ?? null)
                || blank($m['education'] ?? null)
                || blank($m['occupation'] ?? null)
                || blank($m['marital_status'] ?? null)
                || blank($m['family_relation'] ?? null)
            ) {
                $hasIncompleteFields = true;
                break;
            }
        }

        // 1. Status Summary Banner
        if ($isConflict && $conflictKk !== null) {
            $html .= '<div class="rounded-xl border border-red-300 bg-red-50 dark:bg-red-950/40 dark:border-red-800 p-4 text-red-900 dark:text-red-200 shadow-sm">';
            $html .= '<div class="flex items-start gap-3">';
            $html .= '<div class="mt-0.5 text-red-600 dark:text-red-400">';
            $html .= '<svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>';
            $html .= '</div>';
            $html .= '<div class="space-y-2 flex-1">';
            $html .= '<h3 class="font-bold text-sm text-red-900 dark:text-red-100">⚠️ Nomor KK / NIK Sudah Terdaftar di Sistem!</h3>';
            $html .= '<p class="text-xs text-red-700 dark:text-red-300 leading-relaxed">';
            if (! empty($preview['conflict_reason'])) {
                $html .= e($preview['conflict_reason']).' ';
            } else {
                $html .= 'Nomor KK <strong class="font-mono font-bold">'.e($conflictKk['number']).'</strong> atas nama <strong>'.e($conflictKk['kepala']).'</strong> sudah ada di database (ID: #'.e($conflictKk['id']).'). ';
            }
            $html .= 'Mengubah data di sini akan merusak data yang sudah ada.';
            $html .= '</p>';
            $html .= '<div class="flex items-center gap-2 pt-1">';
            if (! empty($conflictKk['edit_url'])) {
                $html .= '<a href="'.e($conflictKk['edit_url']).'" target="_blank" class="inline-flex items-center gap-1 rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-red-700">Lihat / Edit Data KK Tersebut</a>';
            } elseif (! empty($conflictKk['view_url'])) {
                $html .= '<a href="'.e($conflictKk['view_url']).'" target="_blank" class="inline-flex items-center gap-1 rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-red-700">Lihat Data KK Tersebut</a>';
            }
            $html .= '<button type="button" wire:click="closeOcrModal" class="inline-flex items-center gap-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:text-gray-300 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700">Batal / Gunakan Foto Lain</button>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';
        } elseif ($isEmpty) {
            $html .= '<div class="rounded-xl border border-red-200 bg-red-50 dark:bg-red-950/30 dark:border-red-800 p-3.5 text-xs text-red-800 dark:text-red-300 flex items-center gap-2.5">';
            $html .= '<svg class="h-5 w-5 text-red-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
            $html .= '<span class="font-medium">Data KK tidak berhasil dibaca.</span>';
            $html .= '</div>';
        } elseif ($hasIncompleteFields) {
            $html .= '<div class="rounded-xl border border-amber-200 bg-amber-50 dark:bg-amber-950/30 dark:border-amber-800 p-3.5 text-xs text-amber-800 dark:text-amber-300 flex items-center gap-2.5">';
            $html .= '<svg class="h-5 w-5 text-amber-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>';
            $html .= '<span class="font-medium">OCR selesai. Beberapa data anggota perlu diperiksa.</span>';
            $html .= '</div>';
        } else {
            $html .= '<div class="rounded-xl border border-emerald-200 bg-emerald-50 dark:bg-emerald-950/30 dark:border-emerald-800 p-3.5 text-xs text-emerald-800 dark:text-emerald-300 flex items-center gap-2.5">';
            $html .= '<svg class="h-5 w-5 text-emerald-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
            $html .= '<span class="font-medium">OCR berhasil membaca data KK. Silakan periksa hasil sebelum menyimpan.</span>';
            $html .= '</div>';
        }

        // 2. Section 1: Data KK summary (Cards for KK Number, Kode Pos, RT/RW, Confidence + Alamat)
        $html .= '<div>';
        $html .= '<div class="mb-2 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">1. Data Kartu Keluarga</div>';
        $html .= '<div class="grid gap-3 grid-cols-2 md:grid-cols-4">';

        $html .= $this->previewCard(
            'Nomor KK',
            e(($preview['kk_number'] ?? null) ?: '(Tidak terbaca)'),
            $isConflict ? 'text-red-600 font-mono font-bold' : 'font-mono',
        );

        $html .= $this->previewCard(
            'Kode Pos',
            filled($preview['postal_code'] ?? null) ? e($preview['postal_code']) : '(Perlu diperiksa)',
            blank($preview['postal_code'] ?? null) ? 'text-amber-600' : '',
        );

        $html .= $this->previewCard(
            'RT / RW',
            e(($preview['rt'] ?? null) ?: '(Tidak terbaca)'),
        );

        $html .= $this->previewCard(
            'Confidence',
            e(number_format(
                (float) ($preview['confidence'] ?? 0),
                1,
                ',',
                '.',
            ).'%'),
        );

        $html .= '</div>';
        $html .= '</div>';

        // 3. Alamat Card
        $html .= '<div>';
        $html .= '<div class="mb-1 text-xs font-medium text-gray-500 dark:text-gray-400">Alamat Terdeteksi</div>';
        $html .= '<div class="rounded-lg border p-2.5 text-xs bg-gray-50 dark:bg-gray-800 dark:border-gray-700 text-gray-800 dark:text-gray-200 font-medium">';
        $html .= e(($preview['address'] ?? null) ?: '(Alamat tidak terbaca)');
        $html .= '</div>';
        $html .= '</div>';

        // 4. Section 2: Members Table
        $html .= '<div>';
        $html .= '<div class="mb-2 flex items-center justify-between">';
        $html .= '<div class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">2. Daftar Anggota Terdeteksi ('.count($members).' Orang)</div>';
        $html .= '</div>';

        if (empty($members)) {
            $html .= '<div class="rounded-lg border border-dashed p-6 text-center text-xs text-gray-500">';
            $html .= 'Tidak ada anggota yang berhasil dikenali dari foto Kartu Keluarga.';
            $html .= '</div>';
        } else {
            $html .= '<div class="overflow-x-auto rounded-lg border max-h-72 overflow-y-auto shadow-xs border-gray-200 dark:border-gray-700">';
            $html .= '<table class="w-full text-xs text-left divide-y divide-gray-200 dark:divide-gray-700">';
            $html .= '<thead class="bg-gray-50 dark:bg-gray-800 sticky top-0">';
            $html .= '<tr class="text-gray-600 dark:text-gray-300 font-semibold">';
            $html .= '<th class="px-2.5 py-2">No.</th>';
            $html .= '<th class="px-2.5 py-2">Nama Lengkap</th>';
            $html .= '<th class="px-2.5 py-2">NIK</th>';
            $html .= '<th class="px-2.5 py-2">Gender</th>';
            $html .= '<th class="px-2.5 py-2">Tempat / Tgl Lahir</th>';
            $html .= '<th class="px-2.5 py-2">Pendidikan</th>';
            $html .= '<th class="px-2.5 py-2">Pekerjaan</th>';
            $html .= '<th class="px-2.5 py-2">Status Kawin</th>';
            $html .= '<th class="px-2.5 py-2">Hubungan</th>';
            $html .= '<th class="px-2.5 py-2">Status NIK</th>';
            $html .= '</tr>';
            $html .= '</thead>';
            $html .= '<tbody class="divide-y divide-gray-100 dark:divide-gray-800 bg-white dark:bg-gray-900">';

            $badgeCheck = '<span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20">Perlu diperiksa</span>';

            foreach ($members as $index => $member) {
                $html .= '<tr class="hover:bg-gray-50/50 dark:hover:bg-gray-800/50">';

                // No
                $html .= '<td class="px-2.5 py-2 text-gray-500 font-medium">';
                $html .= ($index + 1);
                $html .= '</td>';

                // Nama
                $html .= '<td class="px-2.5 py-2 font-medium text-gray-900 dark:text-gray-100">';
                $html .= filled($member['full_name'] ?? null) ? e($member['full_name']) : $badgeCheck;
                $html .= '</td>';

                // NIK
                $html .= '<td class="px-2.5 py-2 font-mono text-gray-700 dark:text-gray-300">';
                $html .= (filled($member['nik'] ?? null) && strlen($member['nik']) === 16) ? e($member['nik']) : $badgeCheck;
                $html .= '</td>';

                // Gender
                $html .= '<td class="px-2.5 py-2 text-gray-700 dark:text-gray-300">';
                $html .= filled($member['gender'] ?? null) ? e($member['gender']) : $badgeCheck;
                $html .= '</td>';

                // Tempat / Tgl Lahir
                $birthInfo = trim(($member['birth_place'] ?? '').' '.($member['birth_date'] ?? ''));
                $html .= '<td class="px-2.5 py-2 text-gray-700 dark:text-gray-300">';
                $html .= filled($birthInfo) ? e($birthInfo) : $badgeCheck;
                $html .= '</td>';

                // Pendidikan
                $html .= '<td class="px-2.5 py-2 text-gray-700 dark:text-gray-300">';
                $html .= filled($member['education'] ?? null) ? e($member['education']) : $badgeCheck;
                $html .= '</td>';

                // Pekerjaan
                $html .= '<td class="px-2.5 py-2 text-gray-700 dark:text-gray-300">';
                $html .= filled($member['occupation'] ?? null) ? e($member['occupation']) : $badgeCheck;
                $html .= '</td>';

                // Status Perkawinan
                $html .= '<td class="px-2.5 py-2 text-gray-700 dark:text-gray-300">';
                $html .= filled($member['marital_status'] ?? null) ? e($member['marital_status']) : $badgeCheck;
                $html .= '</td>';

                // Hubungan
                $html .= '<td class="px-2.5 py-2 text-gray-700 dark:text-gray-300">';
                $html .= filled($member['family_relation'] ?? null) ? e($member['family_relation']) : $badgeCheck;
                $html .= '</td>';

                // Status NIK
                $html .= '<td class="px-2.5 py-2">';
                if (! empty($member['existing_resident'])) {
                    $html .= '<span class="inline-flex items-center rounded-md bg-blue-50 px-2 py-0.5 text-[11px] font-medium text-blue-700 ring-1 ring-inset ring-blue-700/10" title="KK: '.e($member['existing_resident']['current_kk']).'">Terdaftar</span>';
                } else {
                    $html .= '<span class="inline-flex items-center rounded-md bg-green-50 px-2 py-0.5 text-[11px] font-medium text-green-700 ring-1 ring-inset ring-green-600/20">Baru</span>';
                }
                $html .= '</td>';

                $html .= '</tr>';
            }

            $html .= '</tbody>';
            $html .= '</table>';
            $html .= '</div>';
        }

        $html .= '</div>'; // End Section 2
        $html .= '</div>'; // End Modal body

        // Modal Footer
        $html .= '<div class="flex items-center justify-between border-t border-gray-200 dark:border-gray-800 px-6 py-4 bg-gray-50 dark:bg-gray-800/60">';
        $html .= '<button type="button" wire:click="closeOcrModal" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-4 py-2 text-xs font-semibold text-gray-700 dark:text-gray-300 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 transition">';
        $html .= '<svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>';
        $html .= $isConflict ? 'Batal / Gunakan Foto Lain' : 'Kembali';
        $html .= '</button>';

        if ($isConflict) {
            $html .= '<span class="text-xs text-red-600 dark:text-red-400 font-medium flex items-center gap-1"><svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>Terapkan dinonaktifkan (Konflik Data)</span>';
        } else {
            $html .= '<button type="button" wire:click="applyOcrResult" wire:loading.attr="disabled" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-5 py-2 text-xs font-semibold text-white shadow-sm hover:bg-emerald-700 transition disabled:opacity-50">';
            $html .= '<span wire:loading.remove wire:target="applyOcrResult"><svg class="h-4 w-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Setuju</span>';
            $html .= '<span wire:loading wire:target="applyOcrResult">Menerapkan...</span>';
            $html .= '</button>';
        }

        $html .= '</div></div></div>';

        return new HtmlString($html);
    }

    private function previewCard(string $label, string $value, string $extraClass = ''): string
    {
        return
            '<div class="rounded-lg border p-3 bg-white dark:bg-gray-800 dark:border-gray-700">'
            .'<div class="text-xs text-gray-500 dark:text-gray-400 font-medium">'
            .e($label)
            .'</div>'
            .'<div class="mt-1 font-semibold text-gray-900 dark:text-gray-100 '.e($extraClass).'">'
            .$value
            .'</div>'
            .'</div>';
    }
}
