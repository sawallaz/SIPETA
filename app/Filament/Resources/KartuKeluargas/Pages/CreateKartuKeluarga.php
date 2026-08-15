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
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
     * Triggered by Livewire whenever a bound page property changes — the
     * "Upload Photo -> Automatic Reading" step: as soon as the KK photo is
     * uploaded the scan runs automatically so the form can be reviewed.
     */
    public function updated(string $property): void
    {
        if (
            $property !== 'data.kk_photo'
            && ! str_starts_with($property, 'data.kk_photo.')
        ) {
            return;
        }

        if (blank($this->data['kk_photo'] ?? null)) {
            return;
        }

        $this->scanFoto();
    }

    /**
     * Manual re-scan trigger (header action). Re-runs reading on the current
     * photo, replacing any previously auto-filled values.
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
                    .'Tunggu sampai upload selesai, lalu coba Scan Foto KK kembali.'
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

            $summary = collect($parsed->validationErrors)
                ->merge($parsed->warnings)
                ->filter(fn ($messageOrArray): bool => is_scalar($messageOrArray))
                ->map(fn ($messageOrArray): string => (string) $messageOrArray)
                ->take(3)
                ->implode(' · ');

            Notification::make()
                ->success()
                ->title('Data Kartu Keluarga berhasil dibaca')
                ->body(
                    'Periksa kembali setiap hasil OCR sebelum menyimpan.'
                    .($summary !== '' ? ' '.$summary : '')
                )
                ->send();
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
                ->label('Scan Foto KK')
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
        ]);
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
                if ($uploadedFile instanceof TemporaryUploadedFile) {
                    return $this->storeOcrTemporaryFile($uploadedFile);
                }
            }

            return null;
        }

        return $this->normalizePhotoPath($value);
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
                .'Gunakan JPG atau PNG yang dapat dibuka dengan normal.';
        }

        if (
            str_contains($message, 'resolution below minimum')
        ) {
            return 'Resolusi foto terlalu rendah untuk OCR. '
                .'Gunakan foto KK yang lebih jelas dan beresolusi lebih tinggi.';
        }

        if (
            str_contains($message, 'source image')
            || str_contains($message, 'file does not exist')
            || str_contains($message, 'unable to load')
        ) {
            return 'File foto KK tidak ditemukan atau belum selesai disimpan. '
                .'Silakan upload ulang foto KK.';
        }

        return 'Foto berhasil diterima, tetapi proses OCR gagal. '
            .'Periksa kualitas foto lalu coba Scan Foto KK kembali. '
            .'Jika tetap gagal, data dapat diisi secara manual.';
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
        }

        if ($parsed->address !== null) {
            $data['address'] = $parsed->address;
        }

        /*
         * Wilayah adalah milik KK, bukan milik tiap anggota.
         *
         * RT hasil pemindaian mengisi wilayah pada tingkat KK sehingga
         * operator tidak perlu memilih RT berulang kali per anggota.
         * Nilai yang sudah dipilih operator tidak ditimpa.
         */
        if (blank($data['rt_id'] ?? null)) {
            $rt = $this->resolveRt($parsed->rt);

            if ($rt !== null) {
                $data['area_unit_id'] = $rt->area_unit_id;
                $data['rt_id'] = $rt->id;
            }
        }

        $data['anggota'] = array_map(
            fn (ParsedResident $member): array => $this->memberFromParsed($member),
            $parsed->members,
        );

        $this->form->fill($data);
        $this->data = $data;
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

        if ($value === '' || preg_match('/^\d{1,3}$/', $value) !== 1) {
            return null;
        }

        $number = str_pad((string) ((int) $value), 2, '0', STR_PAD_LEFT);

        return Rt::query()->where('number', $number)->orderBy('id')->first();
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
