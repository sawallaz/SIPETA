<?php

namespace App\Filament\Resources\Penduduks\Schemas;

use App\Enums\BloodType;
use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\ResidentStatus;
use App\Models\KartuKeluarga;
use App\Services\PendudukDocumentService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class PendudukForm
{
    public static function configure(
        Schema $schema,
        array $options = [],
    ): Schema {
        $hideKkId = (bool) ($options['hide_kk_id'] ?? false);

        return $schema
            ->components([

                // 1. IDENTITAS PENDUDUK
                Section::make('Identitas Penduduk')
                    ->description('Informasi dasar dan identitas utama penduduk.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2, 'xl' => 3])
                            ->columnSpanFull()
                            ->schema([
                                TextInput::make('nik')
                                    ->label('NIK')
                                    ->required()
                                    ->unique(
                                        'penduduk',
                                        'nik',
                                        ignoreRecord: true,
                                    )
                                    ->live(debounce: 500)
                                    ->afterStateUpdated(
                                        function ($state, $livewire): void {
                                            if (
                                                method_exists(
                                                    $livewire,
                                                    'checkDuplicateNik'
                                                )
                                            ) {
                                                $livewire->checkDuplicateNik($state);
                                            }
                                        }
                                    )
                                    ->maxLength(16)
                                    ->minLength(16)
                                    ->regex('/^[0-9]{16}$/')
                                    ->rule('digits:16')
                                    ->inputMode('numeric')
                                    ->dehydrateStateUsing(fn ($state): ?string => filled($state) ? preg_replace('/\D/', '', (string) $state) : null)
                                    ->placeholder('16 digit NIK')
                                    ->helperText('NIK adalah identitas unik penduduk. NIK harus 16 digit angka.'),

                                TextInput::make('full_name')
                                    ->label('Nama Lengkap')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('Masukkan nama lengkap'),

                                Select::make('gender')
                                    ->label('Jenis Kelamin')
                                    ->required()
                                    ->options([
                                        Gender::LAKI_LAKI->value => 'Laki-laki',
                                        Gender::PEREMPUAN->value => 'Perempuan',
                                    ])
                                    ->native(false)
                                    ->placeholder('Pilih jenis kelamin'),
                            ]),

                        Grid::make(['default' => 1, 'md' => 2, 'xl' => 3])
                            ->columnSpanFull()
                            ->schema([
                                TextInput::make('birth_place')
                                    ->label('Tempat Lahir')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('Contoh: Parepare'),

                                DatePicker::make('birth_date')
                                    ->label('Tanggal Lahir')
                                    ->required()
                                    ->native(false)
                                    ->displayFormat('d M Y')
                                    ->maxDate(now())
                                    ->placeholder('Pilih tanggal lahir')
                                    ->helperText('Usia dihitung otomatis dari tanggal lahir.'),

                                Select::make('blood_type')
                                    ->label('Golongan Darah')
                                    ->required()
                                    ->default(BloodType::TIDAK_DIKETAHUI->value)
                                    ->options([
                                        BloodType::A->value => 'A',
                                        BloodType::B->value => 'B',
                                        BloodType::AB->value => 'AB',
                                        BloodType::O->value => 'O',
                                        BloodType::TIDAK_DIKETAHUI->value => 'Tidak Diketahui',
                                    ])
                                    ->native(false)
                                    ->placeholder('Pilih golongan darah'),
                            ]),
                    ])
                    ->collapsible(),

                // 2. KARTU KELUARGA
                Section::make('Kartu Keluarga')
                    ->description('Penduduk terdaftar dalam satu Kartu Keluarga. Wilayah mengikuti KK yang dipilih.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2, 'xl' => 3])
                            ->columnSpanFull()
                            ->schema([
                                Select::make('kk_id')
                                    ->label('Kartu Keluarga')
                                    ->relationship('kartuKeluarga', 'kk_number')
                                    ->required(fn (): bool => ! $hideKkId)
                                    ->searchable()
                                    ->preload()
                                    ->native(false)
                                    ->live()
                                    ->placeholder('Pilih nomor KK')
                                    ->hidden($hideKkId)
                                    ->helperText('Pilih Kartu Keluarga tempat penduduk terdaftar.'),

                                Select::make('family_relation')
                                    ->label('Hubungan Keluarga')
                                    ->required()
                                    ->options([
                                        FamilyRelation::KEPALA_KELUARGA->value => 'Kepala Keluarga',
                                        FamilyRelation::ISTRI->value => 'Istri',
                                        FamilyRelation::ANAK->value => 'Anak',
                                        FamilyRelation::MENANTU->value => 'Menantu',
                                        FamilyRelation::CUCU->value => 'Cucu',
                                        FamilyRelation::ORANG_TUA->value => 'Orang Tua',
                                        FamilyRelation::MERTUA->value => 'Mertua',
                                        FamilyRelation::FAMILI_LAIN->value => 'Famili Lain',
                                        FamilyRelation::LAINNYA->value => 'Lainnya',
                                    ])
                                    ->native(false)
                                    ->placeholder('Pilih hubungan keluarga'),

                                Placeholder::make('wilayah_kk')
                                    ->label('Wilayah')
                                    ->content(function (Get $get): string {
                                        $kkId = $get('kk_id');
                                        if (! $kkId) {
                                            return 'Pilih Kartu Keluarga terlebih dahulu.';
                                        }
                                        $kk = KartuKeluarga::query()->with('rt.areaUnit')->find($kkId);
                                        if (! $kk) {
                                            return 'KK tidak ditemukan.';
                                        }

                                        return $kk->rt_rw_label ?? 'Wilayah belum ditentukan.';
                                    })
                                    ->visible(fn (): bool => ! $hideKkId)
                                    ->helperText('Wilayah otomatis mengikuti Kartu Keluarga.'),

                                Placeholder::make('alamat_kk')
                                    ->label('Alamat KK')
                                    ->content(function (Get $get): string {
                                        $kkId = $get('kk_id');
                                        if (! $kkId) {
                                            return 'Pilih Kartu Keluarga terlebih dahulu.';
                                        }
                                        $address = KartuKeluarga::query()->whereKey($kkId)->value('address');

                                        return filled($address) ? $address : 'Alamat belum tersedia.';
                                    })
                                    ->visible(fn (): bool => ! $hideKkId)
                                    ->columnSpanFull()
                                    ->helperText('Alamat mengikuti data Kartu Keluarga.'),
                            ]),
                    ])
                    ->collapsible(),

                // 3. DATA SOSIAL
                Section::make('Data Sosial')
                    ->description('Agama, pendidikan, pekerjaan dan status perkawinan.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])
                            ->columnSpanFull()
                            ->schema([
                                Select::make('religion_id')
                                    ->label('Agama')
                                    ->relationship('religion', 'name')
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->native(false)
                                    ->placeholder('Pilih agama'),

                                Select::make('education_id')
                                    ->label('Pendidikan')
                                    ->relationship('education', 'name')
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->native(false)
                                    ->placeholder('Pilih pendidikan'),

                                Select::make('occupation_id')
                                    ->label('Pekerjaan')
                                    ->relationship('occupation', 'name')
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->native(false)
                                    ->placeholder('Pilih pekerjaan'),

                                Select::make('marital_status')
                                    ->label('Status Perkawinan')
                                    ->required()
                                    ->options([
                                        MaritalStatus::BELUM_KAWIN->value => 'Belum Kawin',
                                        MaritalStatus::KAWIN->value => 'Kawin',
                                        MaritalStatus::CERAI_HIDUP->value => 'Cerai Hidup',
                                        MaritalStatus::CERAI_MATI->value => 'Cerai Mati',
                                    ])
                                    ->native(false)
                                    ->placeholder('Pilih status perkawinan'),
                            ]),
                    ])
                    ->collapsible(),

                // 4. STATUS KEPENDUDUKAN
                Section::make('Status Kependudukan')
                    ->description('Status administrasi penduduk.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2, 'xl' => 3])
                            ->columnSpanFull()
                            ->schema([
                                Select::make('resident_status')
                                    ->label('Status Penduduk')
                                    ->required()
                                    ->live()
                                    ->default(ResidentStatus::ACTIVE->value)
                                    ->afterStateUpdated(function (Set $set, $state) {
                                        if ($state === ResidentStatus::ACTIVE->value) {
                                            $set('active_at', now()->toDateString());
                                        } elseif ($state === ResidentStatus::PINDAH->value) {
                                            $set('moved_at', now()->toDateString());
                                        } elseif ($state === ResidentStatus::MENINGGAL->value) {
                                            $set('deceased_at', now()->toDateString());
                                        }
                                    })
                                    ->options([
                                        ResidentStatus::ACTIVE->value => 'Aktif',
                                        ResidentStatus::PINDAH->value => 'Pindah',
                                        ResidentStatus::MENINGGAL->value => 'Meninggal',
                                    ])
                                    ->native(false)
                                    ->placeholder('Pilih status penduduk'),

                                DatePicker::make('active_at')
                                    ->label('Tanggal Aktif')
                                    ->native(false)
                                    ->displayFormat('d M Y')
                                    ->default(fn () => now())
                                    ->required(fn (Get $get): bool => $get('resident_status') === ResidentStatus::ACTIVE->value || blank($get('resident_status')))
                                    ->visible(fn (Get $get): bool => $get('resident_status') === ResidentStatus::ACTIVE->value || blank($get('resident_status')))
                                    ->helperText('Tanggal kejadian status aktif.'),

                                DatePicker::make('moved_at')
                                    ->label('Tanggal Pindah')
                                    ->native(false)
                                    ->displayFormat('d M Y')
                                    ->default(fn () => now())
                                    ->required(fn (Get $get): bool => $get('resident_status') === ResidentStatus::PINDAH->value)
                                    ->visible(fn (Get $get): bool => $get('resident_status') === ResidentStatus::PINDAH->value)
                                    ->helperText('Tanggal kejadian kepindahan.'),

                                DatePicker::make('deceased_at')
                                    ->label('Tanggal Meninggal')
                                    ->native(false)
                                    ->displayFormat('d M Y')
                                    ->default(fn () => now())
                                    ->required(fn (Get $get): bool => $get('resident_status') === ResidentStatus::MENINGGAL->value)
                                    ->visible(fn (Get $get): bool => $get('resident_status') === ResidentStatus::MENINGGAL->value)
                                    ->helperText('Tanggal kejadian meninggal dunia.'),
                            ]),
                    ])
                    ->collapsible(),

                // 5. DOKUMEN PENDUKUNG
                Section::make('Dokumen Pendukung')
                    ->description('KTP dan Akta Kelahiran bersifat opsional. Upload jika dokumen tersedia.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2])
                            ->columnSpanFull()
                            ->schema([

                                // KTP
                                Placeholder::make('ktp_preview')
                                    ->label('KTP Saat Ini')
                                    ->visible(fn ($record): bool => self::hasActiveDocument($record, 'KTP'))
                                    ->content(fn ($record) => self::documentPreview($record, 'KTP')),

                                FileUpload::make('ktp_document')
                                    ->label('KTP Baru')
                                    ->disk(PendudukDocumentService::DISK)
                                    ->directory('penduduk-documents')
                                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])
                                    ->maxSize(5120)
                                    ->storeFiles(false)
                                    ->downloadable()
                                    ->openable()
                                    ->previewable()
                                    ->helperText('Opsional. JPG, PNG, atau PDF maksimal 5 MB.'),

                                // Akta Kelahiran
                                Placeholder::make('akta_preview')
                                    ->label('Akta Kelahiran Saat Ini')
                                    ->visible(fn ($record): bool => self::hasActiveDocument($record, 'AKTA_KELAHIRAN'))
                                    ->content(fn ($record) => self::documentPreview($record, 'AKTA_KELAHIRAN')),

                                FileUpload::make('akta_kelahiran_document')
                                    ->label('Akta Kelahiran Baru')
                                    ->disk(PendudukDocumentService::DISK)
                                    ->directory('penduduk-documents')
                                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])
                                    ->maxSize(5120)
                                    ->storeFiles(false)
                                    ->downloadable()
                                    ->openable()
                                    ->previewable()
                                    ->helperText('Opsional. JPG, PNG, atau PDF maksimal 5 MB.'),
                            ]),
                    ])
                    ->collapsible(),

                // 6. CATATAN
                Section::make('Catatan Tambahan')
                    ->description('Informasi tambahan yang tidak termasuk dalam data utama.')
                    ->columnSpanFull()
                    ->schema([
                        Textarea::make('notes')
                            ->label('Catatan')
                            ->rows(4)
                            ->columnSpanFull()
                            ->placeholder('Masukkan catatan tambahan jika diperlukan...'),
                    ])
                    ->collapsible(),

                Placeholder::make('duplicate_nik_modal')
                    ->hiddenLabel()
                    ->dehydrated(false)
                    ->content(
                        fn (): Htmlable => new HtmlString(
                            self::duplicateNikModalHtml()
                        )
                    )
                    ->columnSpanFull(),
            ]);
    }

    private static function duplicateNikModalHtml(): string
    {
        return <<<'HTML'
<div
    x-data
    x-show="$wire.duplicateNik && $wire.duplicateNik.nik"
    x-cloak
    x-transition.opacity
    @keydown.escape.window="$wire.closeDuplicateNik()"
    class="kk-dup-modal-overlay"
    style="display: none;"
>
    <div
        class="kk-dup-modal-backdrop"
        @click="$wire.closeDuplicateNik()"
    ></div>

    <div class="kk-dup-modal-panel" role="dialog" aria-modal="true">

        <div class="kk-dup-modal-header">
            <div class="kk-dup-modal-icon">
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="kk-dup-modal-icon-svg"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M12 9v3.75m0 3.75h.008M10.29 3.86 2.82 17.14A1.75 1.75 0 0 0 4.34 19.75h15.32a1.75 1.75 0 0 0 1.52-2.61L13.71 3.86a1.75 1.75 0 0 0-3.42 0Z"
                    />
                </svg>
            </div>

            <div class="kk-dup-modal-heading">
                <h2 class="kk-dup-modal-title">
                    Penduduk sudah terdaftar
                </h2>

                <p class="kk-dup-modal-subtitle">
                    Penduduk dengan NIK ini sudah terdaftar di SIPETA.
                </p>
            </div>
        </div>

        <div class="kk-dup-modal-body">
            <div class="kk-dup-modal-box">
                <div class="kk-dup-modal-row">
                    <div class="kk-dup-modal-label">NIK</div>
                    <div
                        class="kk-dup-modal-value kk-dup-modal-value-strong"
                        x-text="$wire.duplicateNik.nik"
                    ></div>
                </div>

                <div class="kk-dup-modal-row">
                    <div class="kk-dup-modal-label">Nama Lengkap</div>
                    <div
                        class="kk-dup-modal-value"
                        x-text="$wire.duplicateNik.name"
                    ></div>
                </div>

                <div class="kk-dup-modal-row">
                    <div class="kk-dup-modal-label">Nomor KK</div>
                    <div
                        class="kk-dup-modal-value"
                        x-text="$wire.duplicateNik.kk_number"
                    ></div>
                </div>

                <div class="kk-dup-modal-row">
                    <div class="kk-dup-modal-label">Status</div>
                    <div
                        class="kk-dup-modal-value"
                        x-text="$wire.duplicateNik.status"
                    ></div>
                </div>
            </div>

            <p class="kk-dup-modal-note">
                Jika ingin memperbarui data atau memindahkan penduduk ini ke KK lain, buka dan ubah data yang sudah ada.
            </p>
        </div>

        <div class="kk-dup-modal-footer">
            <button
                type="button"
                class="kk-dup-modal-btn kk-dup-modal-btn-secondary"
                @click="$wire.closeDuplicateNik()"
            >
                Batal
            </button>

            <a
                class="kk-dup-modal-btn kk-dup-modal-btn-secondary"
                x-show="$wire.duplicateNik && $wire.duplicateNik.view_url"
                :href="$wire.duplicateNik ? $wire.duplicateNik.view_url : '#'"
            >
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="kk-dup-modal-btn-icon"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"
                    />
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"
                    />
                </svg>

                Lihat
            </a>

            <button
                type="button"
                class="kk-dup-modal-btn kk-dup-modal-btn-primary"
                x-show="$wire.duplicateNik && $wire.duplicateNik.assign_allowed"
                @click="$wire.assignExistingToKk($wire.duplicateNik.id)"
            >
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="kk-dup-modal-btn-icon"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.765Z"
                    />
                </svg>

                Pindahkan ke KK Ini
            </button>

            <a
                class="kk-dup-modal-btn kk-dup-modal-btn-primary"
                :href="$wire.duplicateNik ? $wire.duplicateNik.edit_url : '#'"
            >
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="kk-dup-modal-btn-icon"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="m16.86 4.49 1.69-1.69a1.88 1.88 0 1 1 2.65 2.65l-1.69 1.69m-2.65-2.65L7.5 13.85V16.5h2.65l9.36-9.36m-2.65-2.65 2.65 2.65M19.5 13.5v5.63A1.88 1.88 0 0 1 17.63 21H4.88A1.88 1.88 0 0 1 3 19.13V6.38A1.88 1.88 0 0 1 4.88 4.5h5.62"
                    />
                </svg>

                Ubah
            </a>
        </div>

    </div>
</div>
HTML;
    }

    // -----------------------------------------------------------------------
    // Helper methods
    // -----------------------------------------------------------------------

    private static function hasActiveDocument($record, string $type): bool
    {
        if (! $record) {
            return false;
        }

        return $record->documents()
            ->where('document_type', $type)
            ->where('is_active', true)
            ->exists();
    }

    private static function documentPreview($record, string $type): Htmlable
    {
        if (! $record) {
            return new HtmlString('');
        }

        $doc = $record->documents()
            ->where('document_type', $type)
            ->where('is_active', true)
            ->latest('id')
            ->first();

        if (! $doc) {
            return new HtmlString('');
        }

        $url = route('penduduk-documents.preview', $doc);
        $isImage = in_array($doc->mime_type, ['image/jpeg', 'image/png'], true);

        if ($isImage) {
            return new HtmlString('
                <div class="mb-2">
                    <a href="'.e($url).'" target="_blank" class="inline-block">
                        <img src="'.e($url).'" class="max-h-40 rounded-lg border object-contain shadow-sm" alt="Preview Dokumen">
                    </a>
                </div>
            ');
        }

        return new HtmlString('
            <div class="mb-2">
                <a href="'.e($url).'" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-xs font-semibold text-gray-700 rounded-lg border border-gray-300">
                    <svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    Lihat Dokumen PDF
                </a>
            </div>
        ');
    }
}
