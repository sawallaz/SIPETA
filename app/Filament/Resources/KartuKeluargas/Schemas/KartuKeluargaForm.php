<?php

namespace App\Filament\Resources\KartuKeluargas\Schemas;

use App\Models\AreaUnit;
use App\Models\Rt;
use App\Services\KkPhotoService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class KartuKeluargaForm
{
    public static function components(): array
    {
        return [

            /*
             * ================================================================
             * 1. DOKUMEN KK
             * ================================================================
             */

            Section::make('Dokumen Kartu Keluarga')
                ->description(
                    'Upload foto atau scan KK untuk arsip.'
                )
                ->icon(Heroicon::OutlinedDocumentText)
                ->columns(1)
                ->schema([

                    FileUpload::make('kk_photo')
                        ->label('Foto / Scan Kartu Keluarga')
                        ->disk(KkPhotoService::DISK)
                        ->directory('kk-photos')
                        ->storeFiles(false)

                        /*
                         * Penting: tanpa ->live(), penyelesaian upload
                         * tidak memancarkan event 'updated' ke Livewire,
                         * sehingga updated() (trigger OCR otomatis) tidak
                         * terpanggil. ->live() membuat perubahan state foto
                         * diteruskan sebagai update reaktif.
                         */
                        ->live()
                        ->maxSize(25600)
                        ->acceptedFileTypes([
                            'image/jpeg',
                            'image/png',
                            'image/jpg',
                        ])
                        ->downloadable()
                        ->openable()
                        ->previewable()
                        ->helperText(
                            fn (string $operation): string => $operation === 'edit'
                                ? 'Upload hanya jika ingin mengganti foto KK lama (Maks. 10 MB, JPG/PNG).'
                                : 'Upload foto KK untuk arsip dan pembacaan OCR (Maks. 10 MB, JPG/PNG).'
                        )
                        ->columnSpanFull(),

                    Placeholder::make('current_kk_photo')
                        ->label('Foto KK Saat Ini')
                        ->content(
                            fn ($record): Htmlable => new HtmlString(
                                $record !== null
                                && filled($record->active_photo_full_url ?? $record->active_photo_thumbnail_url)
                                    ? '
                                        <div x-data="{ open: false }" class="flex flex-col items-center">
                                            <button
                                                type="button"
                                                @click="open = true"
                                                class="group relative cursor-zoom-in rounded-xl border border-gray-200 p-2 shadow-sm transition hover:shadow-md dark:border-gray-700"
                                            >
                                                <img
                                                    src="'.e($record->active_photo_full_url ?? $record->active_photo_thumbnail_url).'"
                                                    class="max-h-96 w-auto max-w-full rounded-lg object-contain"
                                                    alt="Foto KK saat ini"
                                                >
                                                <div class="absolute inset-0 flex items-center justify-center rounded-lg bg-black/40 opacity-0 transition group-hover:opacity-100">
                                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white/90 px-3 py-1.5 text-xs font-semibold text-gray-900 shadow-sm">
                                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7"/></svg>
                                                        Klik untuk memperbesar
                                                    </span>
                                                </div>
                                            </button>

                                            <!-- Modal Lightbox Resolusi Penuh -->
                                            <template x-teleport="body">
                                                <div
                                                    x-show="open"
                                                    x-cloak
                                                    @keydown.escape.window="open = false"
                                                    class="fixed inset-0 z-[100] flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm"
                                                >
                                                    <div @click.away="open = false" class="relative flex max-h-[95vh] max-w-[95vw] flex-col overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-gray-900">
                                                        <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                                                            <span class="text-sm font-semibold text-gray-800 dark:text-gray-200">Foto / Scan Kartu Keluarga</span>
                                                            <div class="flex items-center gap-2">
                                                                <a href="'.e($record->active_photo_full_url ?? $record->active_photo_thumbnail_url).'" target="_blank" class="rounded-lg bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300">Buka Tab Baru</a>
                                                                <button type="button" @click="open = false" class="rounded-lg p-1 text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800">
                                                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                                </button>
                                                            </div>
                                                        </div>
                                                        <div class="flex max-h-[85vh] items-center justify-center overflow-auto p-4">
                                                            <img src="'.e($record->active_photo_full_url ?? $record->active_photo_thumbnail_url).'" class="max-h-[80vh] w-auto max-w-full rounded-lg object-contain shadow-sm" alt="Foto KK Full">
                                                        </div>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    '
                                    : '
                                        <div class="rounded-xl border border-dashed p-8 text-center text-sm text-gray-500">
                                            Belum ada foto KK.
                                        </div>
                                    '
                            )
                        )
                        ->columnSpanFull(),
                ])
                ->collapsible(),

            /*
             * ================================================================
             * 2. DATA KARTU KELUARGA
             * ================================================================
             *
             * Sumber wilayah:
             *
             * Kartu Keluarga
             *      ↓
             * RW
             *      ↓
             * RT
             *
             * Penduduk TIDAK memilih wilayah sendiri.
             */

            Section::make('Data Kartu Keluarga')
                ->description(
                    'Informasi utama KK dan wilayah domisili keluarga.'
                )
                ->icon(Heroicon::OutlinedIdentification)
                ->columns([
                    'default' => 1,
                    'md' => 2,
                ])
                ->schema([

                    /*
                     * --------------------------------------------------------
                     * NOMOR KK
                     * --------------------------------------------------------
                     */

                    TextInput::make('kk_number')
                        ->label('Nomor KK')
                        ->required()

                        /*
                         * Tetap dipertahankan sebagai pengaman validasi.
                         *
                         * ignoreRecord = true:
                         * Saat EDIT KK, nomor KK milik record tersebut
                         * tidak dianggap duplicate terhadap dirinya sendiri.
                         */
                        ->unique(
                            'kartu_keluarga',
                            'kk_number',
                            ignoreRecord: true,
                        )

                        /*
                         * Cek database ketika operator selesai mengetik.
                         *
                         * Debounce 500ms mencegah query pada setiap
                         * keystroke secara langsung.
                         */
                        ->live(debounce: 500)

                        /*
                         * Begitu 16 digit terdeteksi dan nomor sudah ada,
                         * halaman mengisi $duplicateKk sehingga modal
                         * overlay muncul di tengah layar.
                         *
                         * $livewire di-inject BY NAME (schemas Component::
                         * resolveDefaultClosureDependencyForEvaluationByName),
                         * karena itu parameter tidak boleh diberi type-hint.
                         */
                        ->afterStateUpdated(
                            function ($state, $livewire): void {
                                if (
                                    ! method_exists(
                                        $livewire,
                                        'checkDuplicateKk'
                                    )
                                ) {
                                    return;
                                }

                                $livewire->checkDuplicateKk($state);
                            }
                        )

                        ->maxLength(16)
                        ->minLength(16)
                        ->regex('/^[0-9]{16}$/')
                        ->rule('digits:16')
                        ->inputMode('numeric')

                        /*
                         * Simpan hanya digit.
                         */
                        ->dehydrateStateUsing(
                            fn ($state): ?string => filled($state)
                                ? preg_replace(
                                    '/\D/',
                                    '',
                                    (string) $state
                                )
                                : null
                        )

                        ->placeholder('Masukkan 16 digit Nomor KK')

                        ->helperText(
                            'Nomor KK harus terdiri dari 16 digit.'
                        ),

                    /*
                     * --------------------------------------------------------
                     * KODE POS
                     * --------------------------------------------------------
                     */

                    TextInput::make('postal_code')
                        ->label('Kode Pos')
                        ->nullable()
                        ->maxLength(5)
                        ->regex('/^[0-9]{5}$/')
                        ->rule('digits:5')
                        ->inputMode('numeric')
                        ->placeholder('Contoh: 90711'),

                    /*
                     * --------------------------------------------------------
                     * ALAMAT
                     * --------------------------------------------------------
                     */

                    TextInput::make('address')
                        ->label('Alamat Lengkap')
                        ->required()
                        ->maxLength(255)
                        ->placeholder(
                            'Masukkan alamat sesuai Kartu Keluarga'
                        )
                        ->columnSpanFull(),

                    /*
                     * ========================================================
                     * RW
                     * ========================================================
                     *
                     * area_unit_id bukan kolom langsung di kartu_keluarga.
                     *
                     * Field ini hanya membantu memilih RT.
                     *
                     * Yang benar-benar disimpan:
                     *
                     * KK → rt_id
                     * RT  → area_unit_id
                     */

                    Select::make('area_unit_id')
                        ->label('RW')
                        ->options(
                            fn (): array => AreaUnit::query()
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(
                                    fn (AreaUnit $area): array => [
                                        $area->id => self::areaUnitLabel($area),
                                    ]
                                )
                                ->all()
                        )
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->live()
                        ->dehydrated(false)

                        /*
                         * Saat edit KK:
                         * tampilkan wilayah berdasarkan RT tersimpan.
                         */
                        ->afterStateHydrated(
                            function (
                                Select $component,
                                $state,
                                $record
                            ): void {
                                if (
                                    blank($state)
                                    && $record?->rt?->area_unit_id !== null
                                ) {
                                    $component->state(
                                        $record->rt->area_unit_id
                                    );
                                }
                            }
                        )

                        /*
                         * Kalau wilayah berubah,
                         * RT lama harus dipilih ulang.
                         */
                        ->afterStateUpdated(
                            function (Set $set): void {
                                $set('rt_id', null);
                            }
                        )

                        ->placeholder('Pilih RW')
                        ->helperText(
                            'Pilih wilayah sebelum memilih RT.'
                        )

                        /*
                         * Tambah RW.
                         */
                        ->suffixAction(
                            Action::make('addAreaUnit')
                                ->label('Tambah RW')
                                ->icon('heroicon-o-plus')
                                ->tooltip('Tambah RW')
                                ->modalHeading(
                                    'Tambah RW'
                                )
                                ->modalSubmitActionLabel('Simpan')
                                ->form([

                                    TextInput::make('name')
                                        ->label('Nama RW')
                                        ->required()
                                        ->maxLength(100)
                                        ->placeholder(
                                            'Contoh: RW 01'
                                        ),

                                ])
                                ->action(
                                    function (
                                        array $data,
                                        Set $set
                                    ): void {
                                        $areaUnit = AreaUnit::query()->create([
                                            'name' => trim(
                                                (string) $data['name']
                                            ),
                                            'type' => 'rw',
                                        ]);

                                        $set(
                                            'area_unit_id',
                                            $areaUnit->getKey()
                                        );

                                        $set('rt_id', null);
                                    }
                                )
                        ),

                    /*
                     * ========================================================
                     * RT
                     * ========================================================
                     */

                    Select::make('rt_id')
                        ->label('RT')

                        /*
                         * Hanya RT dari wilayah yang dipilih.
                         */
                        ->options(
                            function (Get $get): array {
                                $areaUnitId = $get('area_unit_id');

                                if (blank($areaUnitId)) {
                                    return [];
                                }

                                return Rt::query()
                                    ->where(
                                        'area_unit_id',
                                        $areaUnitId
                                    )
                                    ->orderBy('number')
                                    ->get()
                                    ->mapWithKeys(
                                        fn (Rt $rt): array => [
                                            $rt->id => 'RT '.$rt->number,
                                        ]
                                    )
                                    ->all();
                            }
                        )
                        ->required()
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->live()
                        ->placeholder('Pilih RT')
                        ->disabled(
                            fn (Get $get): bool => ! $get('area_unit_id')
                        )
                        ->helperText(
                            fn (Get $get): string => $get('area_unit_id')
                                ? 'RT berdasarkan RW yang dipilih.'
                                : 'Pilih RW terlebih dahulu.'
                        )

                        /*
                         * Tambah RT.
                         */
                        ->suffixAction(
                            Action::make('addRt')
                                ->label('Tambah RT')
                                ->icon('heroicon-o-plus')
                                ->tooltip('Tambah RT')
                                ->modalHeading('Tambah RT')
                                ->modalSubmitActionLabel('Simpan')
                                ->disabled(
                                    fn (Get $get): bool => ! $get('area_unit_id')
                                )
                                ->form([

                                    TextInput::make('number')
                                        ->label('Nomor RT')
                                        ->required()
                                        ->maxLength(10)
                                        ->regex('/^[0-9]+$/')
                                        ->placeholder('Contoh: 01')
                                        ->helperText(
                                            'Masukkan nomor RT, misalnya 01.'
                                        ),
                                ])
                                ->action(
                                    function (
                                        array $data,
                                        Get $get,
                                        Set $set
                                    ): void {
                                        $areaUnitId = $get('area_unit_id');

                                        if (! $areaUnitId) {
                                            throw new \RuntimeException(
                                                'Pilih RW terlebih dahulu.'
                                            );
                                        }

                                        $number = preg_replace(
                                            '/\D/',
                                            '',
                                            (string) (
                                                $data['number'] ?? ''
                                            )
                                        );

                                        if ($number === '') {
                                            throw new \RuntimeException(
                                                'Nomor RT harus diisi.'
                                            );
                                        }

                                        /*
                                         * Jangan membuat RT duplicate
                                         * dalam satu wilayah.
                                         */
                                        $existing = Rt::query()
                                            ->where(
                                                'area_unit_id',
                                                $areaUnitId
                                            )
                                            ->where(
                                                'number',
                                                $number
                                            )
                                            ->first();

                                        if ($existing !== null) {
                                            $set(
                                                'rt_id',
                                                $existing->getKey()
                                            );

                                            return;
                                        }

                                        $rt = Rt::query()->create([
                                            'area_unit_id' => $areaUnitId,
                                            'number' => $number,
                                        ]);

                                        $set(
                                            'rt_id',
                                            $rt->getKey()
                                        );
                                    }
                                )
                        ),

                    /*
                     * ========================================================
                     * CATATAN
                     * ========================================================
                     */

                    Textarea::make('notes')
                        ->label('Catatan')
                        ->nullable()
                        ->rows(3)
                        ->placeholder(
                            'Catatan tambahan...'
                        )
                        ->columnSpanFull(),
                ])
                ->collapsible(),

            /*
             * ================================================================
             * 3. MODAL DUPLICATE KK
             * ================================================================
             *
             * BUKAN card di dalam form. Ini overlay fixed yang menutupi
             * layar dan muncul di tengah ketika nomor KK 16 digit yang
             * diketik sudah terdaftar.
             *
             * Sumber data: properti Livewire $duplicateKk pada halaman
             * (trait ChecksDuplicateKkNumber), diisi oleh
             * checkDuplicateKk() dari afterStateUpdated() field kk_number.
             *
             * CATATAN STYLING (diverifikasi terhadap CSS ter-build):
             * utility Tailwind generik (fixed, inset-0, rounded-2xl,
             * bg-amber-100, bg-primary-600, max-w-lg, ...) TIDAK ada di
             * public/css/filament/filament/app.css maupun di bundle
             * sipeta-admin — Filament v4 hanya mengekspos kelas .fi-*.
             * Karena itu modal memakai kelas `kk-dup-modal-*` yang
             * didefinisikan di resources/css/sipeta-admin.css.
             */

            Placeholder::make('duplicate_kk_modal')
                ->hiddenLabel()
                ->dehydrated(false)
                ->content(
                    fn (): Htmlable => new HtmlString(
                        self::duplicateKkModalHtml()
                    )
                )
                ->columnSpanFull(),
        ];
    }

    /**
     * Markup modal duplicate KK.
     *
     * Alpine membaca $wire.duplicateKk sehingga isi modal selalu
     * mengikuti state terakhir tanpa perlu re-render server.
     *
     * Tombol "Tutup" memanggil closeDuplicateKk() pada halaman.
     */
    private static function duplicateKkModalHtml(): string
    {
        return <<<'HTML'
<div
    x-data
    x-show="$wire.duplicateKk && $wire.duplicateKk.number"
    x-cloak
    x-transition.opacity
    @keydown.escape.window="$wire.closeDuplicateKk()"
    class="kk-dup-modal-overlay"
    style="display: none;"
>
    <div
        class="kk-dup-modal-backdrop"
        @click="$wire.closeDuplicateKk()"
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
                    Nomor KK sudah terdaftar
                </h2>

                <p class="kk-dup-modal-subtitle">
                    Kartu Keluarga dengan nomor ini sudah ada di SIPETA.
                </p>
            </div>
        </div>

        <div class="kk-dup-modal-body">
            <div class="kk-dup-modal-box">
                <div class="kk-dup-modal-row">
                    <div class="kk-dup-modal-label">Nomor KK</div>
                    <div
                        class="kk-dup-modal-value kk-dup-modal-value-strong"
                        x-text="$wire.duplicateKk.number"
                    ></div>
                </div>

                <div class="kk-dup-modal-row">
                    <div class="kk-dup-modal-label">Kepala Keluarga</div>
                    <div
                        class="kk-dup-modal-value"
                        x-text="$wire.duplicateKk.kepala"
                    ></div>
                </div>

                <div class="kk-dup-modal-row">
                    <div class="kk-dup-modal-label">Alamat</div>
                    <div
                        class="kk-dup-modal-value"
                        x-text="$wire.duplicateKk.address"
                    ></div>
                </div>

                <div class="kk-dup-modal-row">
                    <div class="kk-dup-modal-label">RT / RW</div>
                    <div
                        class="kk-dup-modal-value"
                        x-text="($wire.duplicateKk.rt || '-') + ' / ' + ($wire.duplicateKk.rw || '-')"
                    ></div>
                </div>

                <div class="kk-dup-modal-row">
                    <div class="kk-dup-modal-label">Jumlah Anggota</div>
                    <div
                        class="kk-dup-modal-value"
                        x-text="$wire.duplicateKk.member_count"
                    ></div>
                </div>
            </div>

            <p class="kk-dup-modal-note">
                Jika ingin memperbarui data KK tersebut, buka data KK lama.
                Jangan membuat KK baru dengan nomor yang sama.
            </p>
        </div>

        <div class="kk-dup-modal-footer">
            <button
                type="button"
                class="kk-dup-modal-btn kk-dup-modal-btn-secondary"
                @click="$wire.closeDuplicateKk()"
            >
                Batal
            </button>

            <a
                class="kk-dup-modal-btn kk-dup-modal-btn-secondary"
                x-show="$wire.duplicateKk && $wire.duplicateKk.view_url"
                :href="$wire.duplicateKk ? $wire.duplicateKk.view_url : '#'"
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

            <a
                class="kk-dup-modal-btn kk-dup-modal-btn-primary"
                :href="$wire.duplicateKk ? $wire.duplicateKk.edit_url : '#'"
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

    /**
     * Label wilayah yang ditampilkan di form.
     *
     * Prioritas:
     * 1. display_label jika tersedia.
     * 2. type + name sebagai fallback.
     */
    private static function areaUnitLabel(
        AreaUnit $area
    ): string {
        if (filled($area->display_label)) {
            return (string) $area->display_label;
        }

        $type = match ($area->type) {
            'rw' => 'RW',
            default => 'RW',
        };

        return $type
            ? $type.' '.$area->name
            : $area->name;
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components(
            self::components()
        );
    }
}
