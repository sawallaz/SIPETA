<?php

namespace App\Filament\Resources\KartuKeluargas\Pages;

use App\Enums\BloodType;
use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Enums\KkAnggotaStatus;
use App\Enums\MaritalStatus;
use App\Enums\OcrJobStatus;
use App\Enums\ResidentStatus;
use App\Filament\Resources\KartuKeluargas\KartuKeluargaResource;
use App\Filament\Resources\KartuKeluargas\Pages\Concerns\ChecksDuplicateKkNumber;
use App\Filament\Resources\KartuKeluargas\Schemas\KartuKeluargaForm;
use App\Models\Education;
use App\Models\KartuKeluarga;
use App\Models\KkAnggota;
use App\Models\Occupation;
use App\Models\OcrJob;
use App\Models\Penduduk;
use App\Models\Religion;
use App\Models\Rt;
use App\Services\KkPhotoService;
use App\Services\OcrProcessingService;
use App\Services\ParsedOcrResult;
use App\Services\ParsedResident;
use App\Services\PendudukDocumentService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * Tambah Kartu Keluarga (Phase UI-3).
 *
 * OCR is integrated into the KK creation workflow — there is no separate
 * "OCR job / Review OCR" page anymore. The operator either:
 *
 *   A. Input Manual — types the household data directly (photo still mandatory),
 *   B. Scan Foto KK  — uploads the KK photo; reading starts automatically and
 *                      the parsed values are pre-filled into the form for the
 *                      operator to review before saving.
 *
 * Saving always creates the KartuKeluarga, its Penduduk members, and the
 * archived KK photo in one transaction. The internal OCR services + ocr_jobs
 * audit trail are reused unchanged (ADR-009: OCR is an assistant; nothing is
 * persisted until the operator saves).
 */
class CreateKartuKeluarga extends CreateRecord
{
    use ChecksDuplicateKkNumber;

    protected static string $resource = KartuKeluargaResource::class;

    /**
     * Hilangkan action bawaan "Create & create another".
     *
     * Footer hanya menyisakan "Simpan Kartu Keluarga" dan "Batal".
     */
    protected static bool $canCreateAnother = false;

    /** Early-cycle guard so re-entrant state updates during a scan are no-ops. */
    protected bool $scanning = false;

    /** Transient OCR preview payload shown in the review modal before applying to form. */
    public array $ocrPreview = [];

    /** Controls visibility of the OCR Review Modal overlay. */
    public bool $isOcrModalOpen = false;

    /**
     * Path OCR temp (on kk_uploads disk) that must be cleaned after scan.
     */
    protected ?string $ocrTempPath = null;

    private const GENDER_LABELS = [
        Gender::LAKI_LAKI->value => 'Laki-laki',
        Gender::PEREMPUAN->value => 'Perempuan',
    ];

    private const MARITAL_LABELS = [
        MaritalStatus::BELUM_KAWIN->value => 'Belum Kawin',
        MaritalStatus::KAWIN->value => 'Kawin',
        MaritalStatus::CERAI_HIDUP->value => 'Cerai Hidup',
        MaritalStatus::CERAI_MATI->value => 'Cerai Mati',
    ];

    private const RELATION_LABELS = [
        FamilyRelation::KEPALA_KELUARGA->value => 'Kepala Keluarga',
        FamilyRelation::ISTRI->value => 'Istri',
        FamilyRelation::ANAK->value => 'Anak',
        FamilyRelation::MENANTU->value => 'Menantu',
        FamilyRelation::CUCU->value => 'Cucu',
        FamilyRelation::ORANG_TUA->value => 'Orang Tua',
        FamilyRelation::MERTUA->value => 'Mertua',
        FamilyRelation::FAMILI_LAIN->value => 'Famili Lain',
        FamilyRelation::LAINNYA->value => 'Lainnya',
    ];

    private const BLOOD_LABELS = [
        'A' => 'A',
        'B' => 'B',
        'AB' => 'AB',
        'O' => 'O',
        BloodType::TIDAK_DIKETAHUI->value => 'Tidak Diketahui',
    ];

    public function getTitle(): string
    {
        return 'Tambah Kartu Keluarga';
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Kartu Keluarga berhasil disimpan';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Simpan Kartu Keluarga')
            ->icon('heroicon-o-check');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label('Batal')
            ->icon('heroicon-o-x-mark');
    }

    /**
     * Livewire updated hook. OCR is strictly manual and must NOT auto-trigger
     * on photo upload so the operator can preview or change the photo first.
     */
    public function updated(string $property): void
    {
        // Passive upload: preview only. OCR is triggered via "Scan Foto dengan OCR" button.
    }

    /**
     * Manual scan trigger (header action & field action). Runs OCR reading on the current
     * photo, filling or replacing scanned values.
     */
    public function scanFoto(): void
    {
        $rawPath = $this->data['kk_photo'] ?? null;

        $path = $this->resolveOcrDiskPath($rawPath);

        if ($path === null) {
            Notification::make()
                ->warning()
                ->title('Foto KK belum siap')
                ->body(
                    'Foto Kartu Keluarga belum tersedia sebagai file yang dapat diproses. '
                    .'Silakan pilih atau upload foto KK terlebih dahulu, lalu tekan Scan Foto dengan OCR.'
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
                        .'yang berhasil dikenali. Periksa kualitas foto atau lengkapi '
                        .'data secara manual.'
                    )
                    ->send();

                return;
            }

            if ($parsed->isValid()) {
                Notification::make()
                    ->success()
                    ->title('Data KK berhasil dibaca')
                    ->body('Silakan periksa hasil OCR sebelum menyimpan.')
                    ->send();
            } else {
                Notification::make()
                    ->info()
                    ->title('OCR Selesai')
                    ->body('Beberapa field perlu diperiksa sebelum menyimpan.')
                    ->send();
            }
        } catch (Throwable $e) {
            Log::warning('KK scan failed', [
                'error' => $e->getMessage(),
                'exception' => $e::class,
                'photo_state_type' => get_debug_type($rawPath),
                'normalized_path' => $path,
            ]);

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
     * "Input Manual" header action — clears the auto-filled scanned values
     * (keeps the photo) so the operator can type everything by hand.
     */
    public function resetScan(): void
    {
        $data = $this->data ?? [];

        $data['kk_number'] = null;
        $data['address'] = null;
        $data['anggota'] = [];

        $this->form->fill($data);
        $this->data = $data;

        Notification::make()
            ->info()
            ->title('Input Manual')
            ->body('Isian dari pemindaian dikosongkan. Isi formulir secara manual.')
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('scanFotoKk')
                ->label('Scan Foto dengan OCR')
                ->icon('heroicon-m-camera')
                ->color('primary')
                ->action(fn () => $this->scanFoto()),
            Action::make('inputManual')
                ->label('Input Manual')
                ->icon('heroicon-m-backspace')
                ->color('gray')
                ->action(fn () => $this->resetScan()),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            ...KartuKeluargaForm::components(),
            $this->anggotaSection(),

            Placeholder::make('ocr_modal_overlay')
                ->hiddenLabel()
                ->dehydrated(false)
                ->content(fn (): Htmlable => $this->renderOcrModal())
                ->columnSpanFull(),
        ]);
    }

    public function closeOcrModal(): void
    {
        $this->isOcrModalOpen = false;
        $this->ocrPreview = [];
    }

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

        if (! empty($this->ocrPreview['members'])) {
            $data['anggota'] = $this->ocrPreview['members'];
        }

        $this->form->fill($data);
        $this->data = $data;

        $this->isOcrModalOpen = false;
        $this->ocrPreview = [];
        $this->duplicateKk = [];

        Notification::make()
            ->success()
            ->title('Data hasil OCR berhasil diterapkan')
            ->body('Data Kartu Keluarga dan daftar anggota telah dimasukkan ke formulir.')
            ->send();
    }

    /**
     * Create KK + all Penduduk members + KkAnggota pivot rows + the archived
     * KK photo in ONE transaction. If any member fails, the whole household
     * is rolled back so we never end up with a "KK with 0 anggota".
     */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            $anggota = (array) ($data['anggota'] ?? []);

            $photo = $data['kk_photo'] ?? null;

            unset($data['anggota'], $data['kk_photo']);

            // Pastikan data KK dibuat terlebih dahulu.
            $kk = static::getModel()::create($data);

            // Buat semua penduduk (serta baris pivot kk_anggota).
            $this->createAnggota($kk, $anggota);

            // Simpan arsip foto KK (storeFiles(false) -> TemporaryUploadedFile).
            if ($photo instanceof TemporaryUploadedFile) {
                app(KkPhotoService::class)->storeUploadedFileForKk(
                    $kk->id,
                    $photo,
                    auth()->id()
                );
            }

            return $kk;
        });
    }

    /**
     * Run the reuse OCR pipeline (upload-referencing job -> preprocess ->
     * engine -> parse) over the photo already stored on the kk_uploads disk.
     * The job row is the audit record; nothing domain-related is written.
     */
    /**
     * Normalisasi state FileUpload menjadi satu path string.
     *
     * Filament FileUpload dapat mengembalikan:
     *
     * - string:
     *     "kk/abc.jpg"
     *
     * - array:
     *     ["kk/abc.jpg"]
     *
     * Pada workflow satu foto KK, hanya satu path yang dipakai.
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
     *
     * OCR membaca dari disk kk_uploads. TemporaryUploadedFile (hasil
     * storeFiles(false)) berada di disk livewire-tmp, sehingga byte-nya
     * disalin ke kk_uploads/ocr-tmp terlebih dahulu dan path tersebut
     * yang dikembalikan agar runOcr() dapat membacanya.
     *
     * String/array (path yang sudah ada di disk) diteruskan apa adanya.
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
     * Salin byte TemporaryUploadedFile (hasil storeFiles(false)) ke disk
     * kk_uploads/ocr-tmp agar runOcr() dapat membacanya, lalu kembalikan
     * path-nya.
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
                : strtolower($match[1]);
        } elseif (str_starts_with($bytes, "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A")) {
            $extension = 'png';
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
     * Hapus file OCR temp di disk kk_uploads apabila berasal dari
     * TemporaryUploadedFile.
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
                .'Tunggu upload selesai, kemudian tekan Scan Foto KK kembali.';
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
            'kk_id' => null,
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

    private function applyParsed(ParsedOcrResult $parsed): void
    {
        $data = $this->data ?? [];

        if ($parsed->kkNumber !== null) {
            $data['kk_number'] = $parsed->kkNumber;
            $this->checkDuplicateKk($parsed->kkNumber);
        }

        $isKkConflict = false;
        $conflictKkData = null;
        $conflictReason = null;

        // 1. Check if parsed KK number belongs to another KK in database
        if (filled($parsed->kkNumber)) {
            $cleanNumber = preg_replace('/\D/', '', (string) $parsed->kkNumber);
            if (strlen($cleanNumber) === 16) {
                $conflictRecord = KartuKeluarga::query()
                    ->with(['rt.areaUnit'])
                    ->where('kk_number', $cleanNumber)
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
            'confidence' => $parsed->confidence,
            'members' => $members,
            'is_kk_conflict' => $isKkConflict,
            'conflict_kk' => $conflictKkData,
            'conflict_reason' => $conflictReason,
        ];

        $this->isOcrModalOpen = true;

        if ($parsed->kkNumber !== null) {
            $data['kk_number'] = $parsed->kkNumber;
        }

        if ($parsed->address !== null) {
            $data['address'] = $parsed->address;
        }

        if ($parsed->postalCode !== null) {
            $data['postal_code'] = $parsed->postalCode;
        }

        if (blank($data['rt_id'] ?? null)) {
            $rt = $this->resolveRt($parsed->rt);

            if ($rt !== null) {
                $data['area_unit_id'] = $rt->area_unit_id;
                $data['rt_id'] = $rt->id;
            }
        }

        $data['anggota'] = $members;

        $this->form->fill($data);
        $this->data = $data;
    }

    /**
     * Render modal review OCR overlay untuk Tambah Kartu Keluarga.
     */
    private function renderOcrModal(): Htmlable
    {
        if (! $this->isOcrModalOpen || blank($this->ocrPreview)) {
            return new HtmlString('');
        }

        $preview = $this->ocrPreview;
        $members = (array) ($preview['members'] ?? []);
        $isConflict = ! empty($preview['is_kk_conflict']);
        $conflictKk = $preview['conflict_kk'] ?? null;

        $html = <<<'HTML'
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/75 p-4 backdrop-blur-xs transition-opacity duration-300">
        <div class="relative flex flex-col w-full max-w-5xl max-h-[90vh] bg-white dark:bg-gray-900 rounded-2xl shadow-2xl overflow-hidden border border-gray-200 dark:border-gray-800">

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
                || blank($m['religion_id'] ?? null)
                || blank($m['education_id'] ?? null)
                || blank($m['occupation_id'] ?? null)
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
                $eduName = null;
                if (! empty($member['education_id'])) {
                    $eduName = Education::find($member['education_id'])?->name;
                }
                $html .= filled($eduName) ? e($eduName) : $badgeCheck;
                $html .= '</td>';

                // Pekerjaan
                $html .= '<td class="px-2.5 py-2 text-gray-700 dark:text-gray-300">';
                $occName = null;
                if (! empty($member['occupation_id'])) {
                    $occName = Occupation::find($member['occupation_id'])?->name;
                }
                $html .= filled($occName) ? e($occName) : $badgeCheck;
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

    /**
     * Cari KK yang sudah terdaftar berdasarkan nomor KK hasil OCR.
     *
     * Nomor KK harus 16 digit; input pendek/salah format diabaikan
     * (tidak perlu query database).
     */
    private function findExistingKk(?string $kkNumber): ?KartuKeluarga
    {
        $kkNumber = preg_replace(
            '/\D/',
            '',
            (string) $kkNumber
        );

        if (
            strlen($kkNumber) !== 16
            || preg_match('/^\d{16}$/', $kkNumber) !== 1
        ) {
            return null;
        }

        return KartuKeluarga::query()
            ->with([
                'rt.areaUnit',
            ])
            ->where('kk_number', $kkNumber)
            ->first();
    }

    /**
     * Pengaman backend agar OCR (atau input manual) tidak membuat KK duplikat.
     *
     * UI sudah menampilkan alert duplikat, tetapi backend tetap harus
     * menolak penyimpanan bila operator memaksa menekan Simpan.
     *
     * Ini lapis kedua setelah duplicate alert pada KartuKeluargaForm.
     */
    protected function beforeCreate(): void
    {
        $kkNumber = preg_replace(
            '/\D/',
            '',
            (string) ($this->data['kk_number'] ?? '')
        );

        if (
            strlen($kkNumber) !== 16
            || preg_match('/^\d{16}$/', $kkNumber) !== 1
        ) {
            return;
        }

        $existingKk = KartuKeluarga::query()
            ->where('kk_number', $kkNumber)
            ->first();

        if ($existingKk === null) {
            return;
        }

        $editUrl = KartuKeluargaResource::getUrl(
            'edit',
            [
                'record' => $existingKk,
            ]
        );

        Notification::make()
            ->danger()
            ->title('Nomor KK sudah terdaftar')
            ->body(
                'Nomor KK '.$kkNumber.
                ' sudah ada di sistem. '.
                'Silakan buka dan edit KK lama.'
            )
            ->actions([
                Action::make('editKkLama')
                    ->label('Buka & Edit KK Lama')
                    ->url($editUrl),
            ])
            ->persistent()
            ->send();

        /*
         * Jangan izinkan proses CREATE diteruskan.
         */
        throw ValidationException::withMessages([
            'data.kk_number' => 'Nomor KK sudah terdaftar. Gunakan KK yang sudah ada.',
        ]);
    }

    private function memberFromParsed(ParsedResident $member): array
    {
        return [
            'full_name' => $member->nama ?? '',
            'nama' => $member->nama ?? '',
            'nik' => $member->nik ?? '',
            'gender' => $member->gender ?? '',
            'birth_place' => $member->birthPlace ?? '',
            'birth_date' => $member->birthDate ?? '',
            'religion_id' => $this->resolveLookupId(Religion::class, (string) ($member->religion ?? '')),
            'education_id' => $this->resolveLookupId(Education::class, (string) ($member->education ?? '')),
            'occupation_id' => $this->resolveLookupId(Occupation::class, (string) ($member->occupation ?? '')),
            'marital_status' => $member->maritalStatus ?? '',
            'family_relation' => $member->familyRelation ?? '',
            // OCR (Phase 5.5) does not extract golongan darah — ParsedResident
            // has no bloodType field — so a scanned member defaults to
            // TIDAK_DIKETAHUI and the operator corrects it manually.
            'blood_type' => BloodType::TIDAK_DIKETAHUI->value,
            // FileUpload fields are entangled immediately when the OCR
            // repeater state is rendered; initialize optional documents so
            // Livewire has both paths available before an operator uploads.
            'ktp_document' => null,
            'akta_kelahiran_document' => null,
        ];
    }

    /**
     * Resolve the incoming anggota rows to Penduduk + KkAnggota rows under
     * the new household.
     *
     * NIK is the identity of a person, not of a KK. When a NIK already exists
     * in the database the existing Penduduk is NOT duplicated — instead its
     * kk_id is moved to the new KK, its old KK membership is closed (KELUAR +
     * end_date), and a new AKTIF membership is recorded. Only genuinely new
     * NIKs produce a new Penduduk row. (Replaces the old duplicate-NIK
     * rejection that wrongly blocked legitimate KK moves / marriages.)
     */
    private function createAnggota(KartuKeluarga $kk, array $anggota): void
    {
        if ($anggota === []) {
            return;
        }

        /*
         * NIK harus tetap unik secara global.
         *
         * Jika NIK belum ada:
         *     -> buat Penduduk baru
         *
         * Jika NIK sudah ada:
         *     -> JANGAN buat Penduduk baru
         *     -> pindahkan Penduduk yang sama ke KK baru
         *     -> tutup riwayat KK lama
         *     -> buat riwayat KK baru sebagai AKTIF
         */
        $seen = [];

        foreach ($anggota as $row) {
            $nik = trim((string) ($row['nik'] ?? ''));

            if ($nik === '') {
                continue;
            }

            /*
             * Jangan sampai NIK yang sama dimasukkan dua kali
             * dalam satu KK baru.
             */
            if (isset($seen[$nik])) {
                throw ValidationException::withMessages([
                    'data.anggota' => "NIK {$nik} muncul lebih dari satu kali dalam daftar anggota.",
                ]);
            }

            $seen[$nik] = true;
        }

        /*
         * Proses seluruh anggota dalam satu transaksi.
         *
         * handleRecordCreation() sudah membungkus method ini
         * dengan DB::transaction(), sehingga jika satu anggota gagal,
         * seluruh proses KK dibatalkan.
         */
        foreach ($anggota as $row) {
            $nik = trim((string) ($row['nik'] ?? ''));

            if ($nik === '') {
                continue;
            }

            /*
             * Cari Penduduk berdasarkan NIK.
             *
             * NIK adalah identitas orang, bukan identitas KK.
             */
            $penduduk = Penduduk::query()
                ->where('nik', $nik)
                ->first();

            if ($penduduk === null) {
                /*
                 * ============================================================
                 * ORANG BARU
                 * ============================================================
                 */
                $penduduk = Penduduk::create([
                    'kk_id' => $kk->id,
                    'nik' => $nik,
                    'full_name' => (string) $row['full_name'],
                    'gender' => (string) $row['gender'],
                    'birth_place' => (string) $row['birth_place'],
                    'birth_date' => (string) $row['birth_date'],
                    'religion_id' => (int) $row['religion_id'],
                    'education_id' => (int) $row['education_id'],
                    'occupation_id' => (int) $row['occupation_id'],
                    'marital_status' => (string) $row['marital_status'],
                    'family_relation' => (string) $row['family_relation'],
                    'blood_type' => (string) (
                        $row['blood_type']
                        ?? BloodType::TIDAK_DIKETAHUI->value
                    ),
                    'resident_status' => ResidentStatus::ACTIVE->value,
                    'rt_id' => $kk->rt_id,
                ]);
            } else {
                /*
                 * ============================================================
                 * ORANG SUDAH ADA
                 * ============================================================
                 *
                 * Jangan create Penduduk baru.
                 *
                 * Ini berarti orang tersebut sedang:
                 * - pindah KK
                 * - menikah
                 * - menjadi anggota KK lain
                 * - atau sedang diperbaiki datanya
                 */
                $oldKkId = $penduduk->kk_id;

                /*
                 * Tutup hubungan aktif dengan KK sebelumnya.
                 */
                KkAnggota::query()
                    ->where('penduduk_id', $penduduk->id)
                    ->where('status', KkAnggotaStatus::AKTIF->value)
                    ->update([
                        'status' => KkAnggotaStatus::KELUAR->value,
                        'end_date' => now()->toDateString(),
                    ]);

                /*
                 * Update data orang yang sama.
                 *
                 * ID Penduduk tetap sama.
                 * NIK tetap sama.
                 * Hanya KK dan data administratifnya yang diperbarui.
                 */
                $penduduk->update([
                    'kk_id' => $kk->id,
                    'full_name' => (string) $row['full_name'],
                    'gender' => (string) $row['gender'],
                    'birth_place' => (string) $row['birth_place'],
                    'birth_date' => (string) $row['birth_date'],
                    'religion_id' => (int) $row['religion_id'],
                    'education_id' => (int) $row['education_id'],
                    'occupation_id' => (int) $row['occupation_id'],
                    'marital_status' => (string) $row['marital_status'],
                    'family_relation' => (string) $row['family_relation'],
                    'blood_type' => (string) (
                        $row['blood_type']
                        ?? BloodType::TIDAK_DIKETAHUI->value
                    ),
                    'resident_status' => ResidentStatus::ACTIVE->value,
                    'moved_at' => null,
                    'moved_destination' => null,
                    'moved_note' => null,
                    'rt_id' => $kk->rt_id,
                ]);

                /*
                 * Variabel ini sengaja dipertahankan untuk dokumentasi/logika
                 * dan memudahkan debugging bila diperlukan.
                 */
                unset($oldKkId);
            }

            /*
             * ================================================================
             * DOKUMEN PENDUKUNG (OPSIONAL)
             * ================================================================
             *
             * KTP dan Akta Kelahiran BUKAN kolom tabel penduduk.
             * File disimpan ke tabel penduduk_documents lewat
             * PendudukDocumentService dan terhubung melalui penduduk_id.
             *
             * storeFiles(false) membuat state form berupa
             * TemporaryUploadedFile; nilai lain (null / path string lama)
             * diabaikan karena tidak ada byte baru yang perlu disimpan.
             */
            $this->storeAnggotaDocuments($penduduk, $row);

            /*
             * Pastikan hanya ada satu hubungan AKTIF
             * untuk Penduduk ini pada KK baru.
             */
            $existingActiveMembership = KkAnggota::query()
                ->where('kk_id', $kk->id)
                ->where('penduduk_id', $penduduk->id)
                ->where('status', KkAnggotaStatus::AKTIF->value)
                ->first();

            if ($existingActiveMembership !== null) {
                /*
                 * Sudah ada. Update datanya saja.
                 */
                $existingActiveMembership->update([
                    'family_relation' => (string) $row['family_relation'],
                    'effective_date' => $existingActiveMembership->effective_date
                        ?? now()->toDateString(),
                    'end_date' => null,
                ]);

                continue;
            }

            /*
             * Buat riwayat hubungan KK baru.
             */
            KkAnggota::create([
                'kk_id' => $kk->id,
                'penduduk_id' => $penduduk->id,
                'family_relation' => (string) $row['family_relation'],
                'status' => KkAnggotaStatus::AKTIF->value,
                'effective_date' => now()->toDateString(),
                'end_date' => null,
            ]);
        }
    }

    /**
     * Simpan dokumen pendukung satu anggota.
     *
     * Kedua dokumen OPSIONAL. Hanya TemporaryUploadedFile yang diproses —
     * nilai null atau path string lama tidak menghasilkan byte baru.
     *
     * Tipe dokumen mengikuti PendudukDocumentService::ALLOWED_TYPES:
     * KTP dan AKTA_KELAHIRAN.
     *
     * @param  array<string, mixed>  $row
     */
    private function storeAnggotaDocuments(Penduduk $penduduk, array $row): void
    {
        $documents = [
            'ktp_document' => 'KTP',
            'akta_kelahiran_document' => 'AKTA_KELAHIRAN',
        ];

        $service = null;

        foreach ($documents as $field => $documentType) {
            $file = $row[$field] ?? null;

            /*
             * FileUpload non-multiple tetap dapat mengembalikan array
             * berisi satu berkas tergantung waktu hidrasi Livewire.
             */
            if (is_array($file)) {
                $file = collect($file)
                    ->first(
                        fn ($item): bool => $item instanceof TemporaryUploadedFile
                    );
            }

            if (! $file instanceof TemporaryUploadedFile) {
                continue;
            }

            $service ??= app(PendudukDocumentService::class);

            $service->store(
                penduduk: $penduduk,
                file: $file,
                documentType: $documentType,
                operatorId: auth()->id(),
            );
        }
    }

    /**
     * Resolve a lookup label (religion / education / occupation) to its master
     * row id, creating the master row (title-cased) when absent — the masters
     * are an evolving, data-driven taxonomy (mirrors PendudukImportService).
     *
     * @param  class-string<Model>  $model
     */
    private function resolveLookupId(string $model, string $label): ?int
    {
        $label = trim(preg_replace('/\s+/', ' ', $label) ?? $label);

        if ($label === '') {
            return null;
        }

        $existing = $model::query()
            ->whereRaw('UPPER(name) = ?', [mb_strtoupper($label)])
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        if ($model === Education::class) {
            $aliasGroups = [
                'D1' => ['D1', 'D-I', 'D I', 'DIPLOMA I', 'DIPLOMA 1', 'DIPLOMA I/II'],
                'D2' => ['D2', 'D-II', 'D II', 'DIPLOMA II', 'DIPLOMA 2'],
                'D3' => ['D3', 'D-III', 'D III', 'DIPLOMA III', 'DIPLOMA 3', 'AKADEMI', 'SARJANA MUDA', 'AKADEMI/DIPLOMA III/SARJANA MUDA'],
                'S1' => ['S1', 'S-I', 'S I', 'STRATA I', 'STRATA 1', 'SARJANA', 'D4', 'D-IV', 'D IV', 'DIPLOMA IV', 'DIPLOMA IV/STRATA I'],
                'S2' => ['S2', 'S-II', 'S II', 'STRATA II', 'STRATA 2', 'MAGISTER'],
                'S3' => ['S3', 'S-III', 'S III', 'STRATA III', 'STRATA 3', 'DOKTOR'],
                'SMA' => ['SMA', 'SMA/SEDERAJAT', 'SLTA', 'SLTA/SEDERAJAT', 'SMK', 'SMK/SEDERAJAT', 'MA', 'MA/SEDERAJAT', 'SITA/SEDERAJAT', 'SITA', 'SUTA/SEDERAJAT', 'SUTA', 'SLTASEDERAJAT', 'SITASEDERAJAT'],
                'SMP' => ['SMP', 'SMP/SEDERAJAT', 'SLTP', 'SLTP/SEDERAJAT', 'MTS', 'MTS/SEDERAJAT', 'SITP/SEDERAJAT', 'SITP', 'SLTPSEDERAJAT', 'SITPSEDERAJAT'],
                'SD' => ['SD', 'SD/SEDERAJAT', 'TAMAT SD', 'TAMAT SD/SEDERAJAT', 'BELUM TAMAT SD', 'BELUM TAMAT SD/SEDERAJAT'],
                'Tidak/Belum Sekolah' => ['Tidak/Belum Sekolah', 'TIDAK/BELUM SEKOLAH', 'TIDAK BELUM SEKOLAH', 'BELUM SEKOLAH', 'TIDAK SEKOLAH'],
            ];

            $upperInput = mb_strtoupper($label);
            foreach ($aliasGroups as $targetCanonical => $groupAliases) {
                foreach ($groupAliases as $alias) {
                    if ($upperInput === mb_strtoupper($alias)) {
                        $targetId = $model::query()
                            ->whereRaw('UPPER(name) = ?', [mb_strtoupper($targetCanonical)])
                            ->value('id');
                        if ($targetId !== null) {
                            return (int) $targetId;
                        }

                        foreach ($groupAliases as $candidate) {
                            $candId = $model::query()
                                ->whereRaw('UPPER(name) = ?', [mb_strtoupper($candidate)])
                                ->value('id');
                            if ($candId !== null) {
                                return (int) $candId;
                            }
                        }
                        break;
                    }
                }
            }
        }

        if ($model === Occupation::class) {
            $occGroups = [
                'Pegawai Negeri Sipil' => ['Pegawai Negeri Sipil', 'PEGAWAI NEGERI SIPIL', 'PNS', 'ASN', 'PEGAWAI NEGERI'],
                'Ibu Rumah Tangga' => ['Ibu Rumah Tangga', 'IBU RUMAH TANGGA', 'Mengurus Rumah Tangga', 'MENGURUS RUMAH TANGGA', 'RUMAH TANGGA', 'IRT'],
                'Buruh' => ['Buruh', 'BURUH', 'Buruh Harian Lepas', 'BURUH HARIAN LEPAS', 'Buruh Harian', 'BURUH HARIAN', 'Buruh Tani', 'BURUH TANI', 'Buruh Pabrik', 'BURUH PABRIK'],
                'Karyawan Swasta' => ['Karyawan Swasta', 'KARYAWAN SWASTA', 'Karyawan', 'KARYAWAN', 'Pegawai Swasta', 'PEGAWAI SWASTA', 'Karyawan BUMN', 'Karyawan BUMD', 'Swasta', 'SWASTA'],
                'Pelajar/Mahasiswa' => ['Pelajar/Mahasiswa', 'PELAJAR/MAHASISWA', 'Pelajar', 'PELAJAR', 'Mahasiswa', 'MAHASISWA', 'Pelajar Mahasiswa', 'PELAJAR MAHASISWA', 'Pelajarimahasiswa', 'PELAJARIMAHASISWA'],
                'Petani' => ['Petani', 'PETANI', 'Petani/Pekebun', 'PETANI/PEKEBUN', 'Pekebun', 'PEKEBUN', 'Petani Pekebun', 'PETANI PEKEBUN'],
                'Pedagang' => ['Pedagang', 'PEDAGANG', 'Perdagangan', 'PERDAGANGAN'],
                'Nelayan' => ['Nelayan', 'NELAYAN', 'Nelayan/Perikanan', 'NELAYAN/PERIKANAN', 'Perikanan', 'PERIKANAN'],
                'Wiraswasta' => ['Wiraswasta', 'WIRASWASTA', 'Wirausaha', 'WIRAUSAHA'],
                'Pensiunan' => ['Pensiunan', 'PENSIUNAN', 'Pensiun', 'PENSIUN'],
                'Tukang' => ['Tukang', 'TUKANG', 'Tukang Kayu', 'Tukang Batu', 'Tukang Jahit', 'Tukang Cukur', 'Tukang Las'],
                'Lainnya' => ['Lainnya', 'LAINNYA', 'Belum/Tidak Bekerja', 'BELUM/TIDAK BEKERJA', 'Belum Bekerja', 'BELUM BEKERJA', 'Tidak Bekerja', 'TIDAK BEKERJA'],
            ];

            $upperInput = mb_strtoupper($label);
            foreach ($occGroups as $targetCanonical => $groupAliases) {
                foreach ($groupAliases as $alias) {
                    if ($upperInput === mb_strtoupper($alias)) {
                        $targetId = $model::query()
                            ->whereRaw('UPPER(name) = ?', [mb_strtoupper($targetCanonical)])
                            ->value('id');
                        if ($targetId !== null) {
                            return (int) $targetId;
                        }

                        foreach ($groupAliases as $candidate) {
                            $candId = $model::query()
                                ->whereRaw('UPPER(name) = ?', [mb_strtoupper($candidate)])
                                ->value('id');
                            if ($candId !== null) {
                                return (int) $candId;
                            }
                        }
                        break;
                    }
                }
            }
        }

        if ($model === Religion::class) {
            $relGroups = [
                'Islam' => ['Islam', 'ISLAM'],
                'Kristen' => ['Kristen', 'KRISTEN', 'Kristen Protestan', 'PROTESTAN', 'Protestan'],
                'Katolik' => ['Katolik', 'KATOLIK', 'Catholic', 'CATHOLIC'],
                'Hindu' => ['Hindu', 'HINDU'],
                'Buddha' => ['Buddha', 'BUDDHA', 'Budha', 'BUDHA'],
                'Konghucu' => ['Konghucu', 'KONGHUCU', 'Khonghucu', 'KHONGHUCU'],
                'Lainnya' => ['Lainnya', 'LAINNYA', 'Kepercayaan', 'KEPERCAYAAN', 'Penghayat Kepercayaan'],
            ];

            $upperInput = mb_strtoupper($label);
            foreach ($relGroups as $targetCanonical => $groupAliases) {
                foreach ($groupAliases as $alias) {
                    if ($upperInput === mb_strtoupper($alias)) {
                        $targetId = $model::query()
                            ->whereRaw('UPPER(name) = ?', [mb_strtoupper($targetCanonical)])
                            ->value('id');
                        if ($targetId !== null) {
                            return (int) $targetId;
                        }

                        foreach ($groupAliases as $candidate) {
                            $candId = $model::query()
                                ->whereRaw('UPPER(name) = ?', [mb_strtoupper($candidate)])
                                ->value('id');
                            if ($candId !== null) {
                                return (int) $candId;
                            }
                        }
                        break;
                    }
                }
            }
        }

        return (int) $model::create([
            'name' => mb_convert_case($label, MB_CASE_TITLE, 'UTF-8'),
        ])->id;
    }

    /**
     * Resolve the scanned RT (e.g. "001") to an existing Rt by normalized
     * number; null when the RT is not yet registered locally.
     */
    private function resolveRt(?string $value): ?Rt
    {
        $value = trim((string) $value);

        $digits = preg_replace('/\D/', '', $value);
        if ($digits === null || $digits === '' || strlen($digits) > 3) {
            return null;
        }

        $num = (int) $digits;
        $twoDigits = str_pad((string) $num, 2, '0', STR_PAD_LEFT);
        $threeDigits = str_pad((string) $num, 3, '0', STR_PAD_LEFT);
        $raw = (string) $num;

        return Rt::query()->whereIn('number', [$twoDigits, $threeDigits, $raw])->orderBy('id')->first();
    }

    private function anggotaSection(): Section
    {
        return Section::make('Anggota Keluarga')
            ->description(
                'Daftar penduduk anggota Kartu Keluarga. '.
                'Hasil pemindaian akan mengisi data ini secara otomatis.'
            )
            ->schema([
                Repeater::make('anggota')
                    ->label('')
                    ->defaultItems(0)
                    ->addActionLabel('Tambah Anggota')
                    ->itemLabel(
                        fn (array $state): ?string => filled($state['full_name'] ?? null)
                                ? $state['full_name']
                                : 'Anggota Baru'
                    )
                    ->collapsible()
                    ->cloneable(false)
                    ->reorderable(false)
                    ->schema([
                        $this->memberFields(),
                        $this->memberDocumentsSection(),
                    ])
                    ->columnSpanFull(),
            ])
            ->columnSpanFull()
            ->collapsible();
    }

    /**
     * Dokumen pendukung per anggota.
     *
     * KTP dan Akta Kelahiran keduanya OPSIONAL — tidak ada required().
     *
     * File TIDAK disimpan sebagai kolom tabel penduduk. storeFiles(false)
     * membuat nilai state berupa TemporaryUploadedFile yang diserahkan ke
     * PendudukDocumentService (tabel penduduk_documents, terhubung lewat
     * penduduk_id).
     */
    private function memberDocumentsSection(): Section
    {
        return Section::make('Dokumen Pendukung')
            ->description(
                'KTP dan Akta Kelahiran merupakan dokumen pendukung '
                .'dan bersifat opsional.'
            )
            ->schema([
                Grid::make([
                    'default' => 1,
                    'md' => 2,
                ])
                    ->schema([
                        FileUpload::make('ktp_document')
                            ->label('KTP')
                            ->disk(PendudukDocumentService::DISK)
                            ->directory('penduduk-documents')
                            ->extraInputAttributes([
                                'accept' => 'image/*,application/pdf',
                            ])
                            ->acceptedFileTypes([
                                'image/jpeg',
                                'image/png',
                                'application/pdf',
                            ])
                            ->maxSize(5120)
                            ->storeFiles(false)
                            ->downloadable()
                            ->openable()
                            ->helperText(
                                'Opsional · JPG, PNG atau PDF · Maksimal 5 MB'
                            ),

                        FileUpload::make('akta_kelahiran_document')
                            ->label('Akta Kelahiran')
                            ->disk(PendudukDocumentService::DISK)
                            ->directory('penduduk-documents')
                            ->extraInputAttributes([
                                'accept' => 'image/*,application/pdf',
                            ])
                            ->acceptedFileTypes([
                                'image/jpeg',
                                'image/png',
                                'application/pdf',
                            ])
                            ->maxSize(5120)
                            ->storeFiles(false)
                            ->downloadable()
                            ->openable()
                            ->helperText(
                                'Opsional · JPG, PNG atau PDF · Maksimal 5 MB'
                            ),
                    ]),
            ])
            ->columnSpanFull()
            ->collapsible();
    }

    private function memberFields(): Grid
    {
        return Grid::make([
            'default' => 1,
            'sm' => 2,
            'lg' => 3,
            'xl' => 4,
        ])
            ->schema([
                TextInput::make('full_name')
                    ->label('Nama Lengkap')
                    ->required()
                    ->maxLength(100)
                    ->columnSpan([
                        'default' => 1,
                        'lg' => 2,
                    ]),
                TextInput::make('nik')
                    ->label('NIK')
                    ->required()
                    ->maxLength(16)
                    ->minLength(16)
                    ->regex('/^[0-9]{16}$/')
                    ->rule('digits:16')
                    ->inputMode('numeric')
                    ->dehydrateStateUsing(
                        fn ($state): ?string => filled($state)
                            ? preg_replace('/\D/', '', (string) $state)
                            : null
                    )
                    ->placeholder('Masukkan 16 digit NIK')
                    ->helperText('NIK harus terdiri dari 16 digit.'),
                Select::make('gender')
                    ->label('Jenis Kelamin')
                    ->required()
                    ->options(self::GENDER_LABELS),
                Select::make('marital_status')
                    ->label('Status Perkawinan')
                    ->required()
                    ->options(self::MARITAL_LABELS),
                Select::make('family_relation')
                    ->label('Hubungan Keluarga')
                    ->required()
                    ->options(self::RELATION_LABELS),
                TextInput::make('birth_place')
                    ->label('Tempat Lahir')
                    ->required()
                    ->maxLength(100),
                DatePicker::make('birth_date')
                    ->label('Tanggal Lahir')
                    ->required()
                    ->displayFormat('d/m/Y'),
                Select::make('religion_id')
                    ->label('Agama')
                    ->required()
                    ->options(Religion::query()->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->createOptionForm([TextInput::make('name')->required()->maxLength(100)])
                    ->createOptionUsing(fn (array $data): int => (int) Religion::query()->create($data)->getKey()),
                Select::make('education_id')
                    ->label('Pendidikan')
                    ->required()
                    ->options(Education::query()->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->createOptionForm([TextInput::make('name')->required()->maxLength(100)])
                    ->createOptionUsing(fn (array $data): int => (int) Education::query()->create($data)->getKey()),
                Select::make('occupation_id')
                    ->label('Pekerjaan')
                    ->required()
                    ->options(Occupation::query()->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->createOptionForm([TextInput::make('name')->required()->maxLength(100)])
                    ->createOptionUsing(fn (array $data): int => (int) Occupation::query()->create($data)->getKey()),
                Select::make('blood_type')
                    ->label('Golongan Darah')
                    ->default(BloodType::TIDAK_DIKETAHUI->value)
                    ->options(self::BLOOD_LABELS),
            ]);
    }
}
