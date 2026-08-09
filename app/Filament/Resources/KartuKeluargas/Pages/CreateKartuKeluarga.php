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
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
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
use Illuminate\Validation\ValidationException;
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
    protected static string $resource = KartuKeluargaResource::class;

    /** Early-cycle guard so re-entrant state updates during a scan are no-ops. */
    protected bool $scanning = false;

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

    /**
     * Triggered by Livewire whenever a bound page property changes — the
     * "Upload Photo -> Automatic Reading" step: as soon as the KK photo is
     * uploaded the scan runs automatically so the form can be reviewed.
     */
    public function updated(string $property): void
    {
        if ($property !== 'data.kk_photo') {
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
        $path = $this->data['kk_photo'] ?? null;

        if (blank($path)) {
            Notification::make()
                ->warning()
                ->title('Foto KK belum diunggah')
                ->body('Unggah foto/scan Kartu Keluarga terlebih dahulu, lalu ulangi pemindaian.')
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
                    ->title('Foto tidak terbaca otomatis')
                    ->body('Lengkapi formulir secara manual (Input Manual).')
                    ->send();

                return;
            }

            $summary = collect($parsed->validationErrors)
                ->merge($parsed->warnings)
                ->take(3)
                ->implode(' · ');

            Notification::make()
                ->success()
                ->title('Data Kartu Keluarga terbaca')
                ->body('Periksa kembali setiap isian sebelum menyimpan.'.$summary)
                ->send();
        } catch (Throwable $e) {
            Log::warning('KK scan failed', ['error' => $e->getMessage(), 'exception' => $e::class]);

            Notification::make()
                ->danger()
                ->title('Pemindaian gagal')
                ->body('Tidak dapat membaca foto ini. Coba foto lain atau isi manual.')
                ->send();
        } finally {
            $this->scanning = false;
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
            $photoPath = $data['kk_photo'] ?? null;

            unset($data['anggota'], $data['kk_photo']);

            // Pastikan data KK dibuat terlebih dahulu.
            $kk = static::getModel()::create($data);

            // Buat semua penduduk (serta baris pivot kk_anggota).
            $this->createAnggota($kk, $anggota);

            // Simpan arsip foto KK.
            if (filled($photoPath)) {
                app(KkPhotoService::class)->storeForKk(
                    $kk->id,
                    $photoPath,
                    null,
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
    private function runOcr(string $path): ParsedOcrResult
    {
        $disk = Storage::disk(KkPhotoService::DISK);

        $job = OcrJob::create([
            'kk_id' => null,
            'source_image_hash' => hash('sha256', (string) $disk->get($path)),
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
                    ])
                    ->columnSpanFull(),
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
