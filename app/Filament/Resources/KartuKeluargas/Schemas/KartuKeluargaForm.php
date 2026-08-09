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
                        ->image()
                        ->imageEditor()
                        ->maxSize(5120)
                        ->acceptedFileTypes([
                            'image/jpeg',
                            'image/png',
                        ])
                        ->downloadable()
                        ->openable()
                        ->previewable()
                        ->helperText(
                            fn (string $operation): string => $operation === 'edit'
                                    ? 'Upload hanya jika ingin mengganti foto KK lama.'
                                    : 'Upload foto KK untuk arsip dan pembacaan OCR.'
                        )
                        ->columnSpanFull(),

                    Placeholder::make('current_kk_photo')
                        ->label('Foto KK Saat Ini')
                        ->content(
                            fn ($record): Htmlable => new HtmlString(
                                $record !== null
                                && filled($record->active_photo_thumbnail_url)
                                    ? '
                                        <div class="flex justify-center">
                                            <img
                                                src="'.e($record->active_photo_thumbnail_url).'"
                                                class="max-h-72 rounded-xl border object-contain shadow-sm"
                                                alt="Foto KK saat ini"
                                            >
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
             * RW / Lingkungan
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
                        ->unique(
                            'kartu_keluarga',
                            'kk_number',
                            ignoreRecord: true,
                        )
                        ->maxLength(16)
                        ->minLength(16)
                        ->regex('/^[0-9]{16}$/')
                        ->rule('digits:16')
                        ->inputMode('numeric')
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
                     * RW / LINGKUNGAN
                     * ========================================================
                     *
                     * `area_unit_id` BUKAN kolom kartu_keluarga.
                     *
                     * Ini hanya field bantu untuk memilih RT.
                     *
                     * Yang disimpan sebenarnya adalah `rt_id`.
                     */

                    Select::make('area_unit_id')
                        ->label('RW / Lingkungan')
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
                         * Saat edit KK, tampilkan wilayah
                         * berdasarkan RT yang sudah tersimpan.
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
                         * Kalau RW/Lingkungan diganti,
                         * RT lama harus dikosongkan.
                         */
                        ->afterStateUpdated(
                            function (Set $set): void {
                                $set('rt_id', null);
                            }
                        )

                        ->placeholder('Pilih RW / Lingkungan')
                        ->helperText(
                            'Pilih wilayah sebelum memilih RT.'
                        )

                        /*
                         * Tombol tambah RW/Lingkungan.
                         *
                         * Tidak meminta kode wilayah.
                         */
                        ->suffixAction(
                            Action::make('addAreaUnit')
                                ->label('Tambah RW / Lingkungan')
                                ->icon('heroicon-o-plus')
                                ->tooltip('Tambah RW / Lingkungan')
                                ->modalHeading(
                                    'Tambah RW / Lingkungan'
                                )
                                ->modalSubmitActionLabel('Simpan')
                                ->form([

                                    TextInput::make('name')
                                        ->label('Nama RW / Lingkungan')
                                        ->required()
                                        ->maxLength(100)
                                        ->placeholder(
                                            'Contoh: Lingkungan I'
                                        ),

                                    Select::make('type')
                                        ->label('Tipe Wilayah')
                                        ->options([
                                            'lingkungan' => 'Lingkungan',
                                            'rw' => 'RW',
                                        ])
                                        ->default('lingkungan')
                                        ->required()
                                        ->native(false),
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
                                            'type' => $data['type']
                                                ?? 'lingkungan',
                                        ]);

                                        $set(
                                            'area_unit_id',
                                            $areaUnit->getKey()
                                        );

                                        /*
                                         * Karena wilayah baru dipilih,
                                         * RT harus dipilih kembali.
                                         */
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
                         * RT hanya menampilkan RT
                         * dari RW/Lingkungan yang dipilih.
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
                                    ? 'RT berdasarkan RW / Lingkungan yang dipilih.'
                                    : 'Pilih RW / Lingkungan terlebih dahulu.'
                        )

                        /*
                         * Tombol tambah RT.
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
                                                'Pilih RW / Lingkungan terlebih dahulu.'
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
                                         * Jangan membuat RT yang sama
                                         * dua kali dalam satu wilayah.
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
        ];
    }

    /**
     * Label wilayah yang ditampilkan di form.
     *
     * Tidak menggunakan kolom `code`.
     *
     * Prioritas:
     *
     * 1. display_label jika tersedia sebagai accessor.
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
            'lingkungan' => 'Lingkungan',
            default => null,
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
