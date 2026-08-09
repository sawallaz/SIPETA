<?php

namespace App\Filament\Resources\Penduduks\Schemas;

use App\Enums\BloodType;
use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\ResidentStatus;
use App\Models\KartuKeluarga;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PendudukForm
{
    /**
     * Konfigurasi form Penduduk.
     *
     * Prinsip arsitektur:
     *
     * - Penduduk menyimpan identitas individu.
     * - kk_id menentukan Kartu Keluarga tempat penduduk berada.
     * - Wilayah tidak diinput di Penduduk.
     * - Wilayah selalu mengikuti Kartu Keluarga.
     * - RT / RW / Lingkungan dikelola dari Kartu Keluarga.
     *
     * @param  array{hide_kk_id?: bool}  $options
     */
    public static function configure(
        Schema $schema,
        array $options = []
    ): Schema {
        $hideKkId = (bool) ($options['hide_kk_id'] ?? false);

        return $schema
            ->components([

                /*
                 * ============================================================
                 * 1. IDENTITAS PENDUDUK
                 * ============================================================
                 */

                Section::make('Identitas Penduduk')
                    ->description(
                        'Informasi dasar dan identitas utama penduduk.'
                    )
                    ->columnSpanFull()
                    ->schema([

                        Grid::make([
                            'default' => 1,
                            'md' => 2,
                            'xl' => 3,
                        ])
                            ->columnSpanFull()
                            ->schema([

                                TextInput::make('nik')
                                    ->label('NIK')
                                    ->required()
                                    ->unique(
                                        'penduduk',
                                        'nik',
                                        ignoreRecord: true
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
                                    ->placeholder('16 digit NIK')
                                    ->helperText(
                                        'Nomor Induk Kependudukan terdiri dari 16 digit.'
                                    ),

                                TextInput::make('full_name')
                                    ->label('Nama Lengkap')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder(
                                        'Masukkan nama lengkap'
                                    ),

                                Select::make('gender')
                                    ->label('Jenis Kelamin')
                                    ->required()
                                    ->options([
                                        Gender::LAKI_LAKI->value => 'Laki-laki',
                                        Gender::PEREMPUAN->value => 'Perempuan',
                                    ])
                                    ->native(false)
                                    ->placeholder(
                                        'Pilih jenis kelamin'
                                    ),
                            ]),

                        Grid::make([
                            'default' => 1,
                            'md' => 2,
                            'xl' => 3,
                        ])
                            ->columnSpanFull()
                            ->schema([

                                TextInput::make('birth_place')
                                    ->label('Tempat Lahir')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder(
                                        'Contoh: Parepare'
                                    ),

                                DatePicker::make('birth_date')
                                    ->label('Tanggal Lahir')
                                    ->required()
                                    ->native(false)
                                    ->displayFormat('d M Y')
                                    ->maxDate(now())
                                    ->placeholder(
                                        'Pilih tanggal lahir'
                                    )
                                    ->helperText(
                                        'Usia dihitung otomatis dari tanggal lahir.'
                                    ),

                                Select::make('blood_type')
                                    ->label('Golongan Darah')
                                    ->required()
                                    ->default(
                                        BloodType::TIDAK_DIKETAHUI->value
                                    )
                                    ->options([
                                        BloodType::A->value => 'A',
                                        BloodType::B->value => 'B',
                                        BloodType::AB->value => 'AB',
                                        BloodType::O->value => 'O',
                                        BloodType::TIDAK_DIKETAHUI->value => 'Tidak Diketahui',
                                    ])
                                    ->native(false)
                                    ->placeholder(
                                        'Pilih golongan darah'
                                    ),
                            ]),
                    ])
                    ->collapsible(),

                /*
                 * ============================================================
                 * 2. KARTU KELUARGA
                 * ============================================================
                 *
                 * PENTING:
                 *
                 * Tidak ada:
                 * - area_unit_id
                 * - rt_id
                 *
                 * di form Penduduk.
                 *
                 * Wilayah adalah milik KK.
                 */

                Section::make('Kartu Keluarga')
                    ->description(
                        'Penduduk terdaftar dalam satu Kartu Keluarga. Wilayah mengikuti KK yang dipilih.'
                    )
                    ->columnSpanFull()
                    ->schema([

                        Grid::make([
                            'default' => 1,
                            'md' => 2,
                            'xl' => 3,
                        ])
                            ->columnSpanFull()
                            ->schema([

                                /*
                                 * ------------------------------------------------
                                 * KARTU KELUARGA
                                 * ------------------------------------------------
                                 */

                                Select::make('kk_id')
                                    ->label('Kartu Keluarga')
                                    ->relationship(
                                        'kartuKeluarga',
                                        'kk_number'
                                    )
                                    ->required(
                                        fn (): bool => ! $hideKkId
                                    )
                                    ->searchable()
                                    ->preload()
                                    ->native(false)
                                    ->live()
                                    ->placeholder(
                                        'Pilih nomor KK'
                                    )
                                    ->hidden($hideKkId)
                                    ->helperText(
                                        'Pilih Kartu Keluarga tempat penduduk terdaftar.'
                                    ),

                                /*
                                 * ------------------------------------------------
                                 * HUBUNGAN KELUARGA
                                 * ------------------------------------------------
                                 */

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
                                    ->placeholder(
                                        'Pilih hubungan keluarga'
                                    ),

                                /*
                                 * ------------------------------------------------
                                 * WILAYAH KK — READ ONLY
                                 * ------------------------------------------------
                                 *
                                 * Ini bukan field database.
                                 *
                                 * Hanya menampilkan wilayah dari KK.
                                 */

                                Placeholder::make('wilayah_kk')
                                    ->label('Wilayah')
                                    ->content(
                                        function (
                                            Get $get
                                        ): string {
                                            $kkId = $get('kk_id');

                                            if (! $kkId) {
                                                return 'Pilih Kartu Keluarga terlebih dahulu.';
                                            }

                                            $kk = KartuKeluarga::query()
                                                ->with('rt.areaUnit')
                                                ->find($kkId);

                                            if (! $kk) {
                                                return 'Kartu Keluarga tidak ditemukan.';
                                            }

                                            return $kk->rt_rw_label
                                                ?? 'Wilayah belum ditentukan pada KK.';
                                        }
                                    )
                                    ->visible(
                                        fn (): bool => ! $hideKkId
                                    )
                                    ->helperText(
                                        'Wilayah otomatis mengikuti Kartu Keluarga.'
                                    ),
                            ]),

                        /*
                         * ------------------------------------------------
                         * INFORMASI WILAYAH
                         * ------------------------------------------------
                         *
                         * Hanya tampil pada form Penduduk utama.
                         */

                        Placeholder::make('alamat_kk')
                            ->label('Alamat KK')
                            ->content(
                                function (
                                    Get $get
                                ): string {
                                    $kkId = $get('kk_id');

                                    if (! $kkId) {
                                        return 'Pilih Kartu Keluarga terlebih dahulu.';
                                    }

                                    $address = KartuKeluarga::query()
                                        ->whereKey($kkId)
                                        ->value('address');

                                    return filled($address)
                                        ? $address
                                        : 'Alamat belum tersedia pada KK.';
                                }
                            )
                            ->visible(
                                fn (): bool => ! $hideKkId
                            )
                            ->columnSpanFull()
                            ->helperText(
                                'Alamat mengikuti data Kartu Keluarga.'
                            ),
                    ])
                    ->collapsible(),

                /*
                 * ============================================================
                 * 3. DATA SOSIAL
                 * ============================================================
                 */

                Section::make('Data Sosial')
                    ->description(
                        'Agama, pendidikan, pekerjaan dan status perkawinan.'
                    )
                    ->columnSpanFull()
                    ->schema([

                        Grid::make([
                            'default' => 1,
                            'md' => 2,
                            'xl' => 4,
                        ])
                            ->columnSpanFull()
                            ->schema([

                                Select::make('religion_id')
                                    ->label('Agama')
                                    ->relationship(
                                        'religion',
                                        'name'
                                    )
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->native(false)
                                    ->placeholder(
                                        'Pilih agama'
                                    ),

                                Select::make('education_id')
                                    ->label('Pendidikan')
                                    ->relationship(
                                        'education',
                                        'name'
                                    )
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->native(false)
                                    ->placeholder(
                                        'Pilih pendidikan'
                                    ),

                                Select::make('occupation_id')
                                    ->label('Pekerjaan')
                                    ->relationship(
                                        'occupation',
                                        'name'
                                    )
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->native(false)
                                    ->placeholder(
                                        'Pilih pekerjaan'
                                    ),

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
                                    ->placeholder(
                                        'Pilih status perkawinan'
                                    ),
                            ]),
                    ])
                    ->collapsible(),

                /*
                 * ============================================================
                 * 4. STATUS KEPENDUDUKAN
                 * ============================================================
                 */

                Section::make('Status Kependudukan')
                    ->description(
                        'Status administrasi penduduk dan informasi kepindahan atau kematian.'
                    )
                    ->columnSpanFull()
                    ->schema([

                        Grid::make([
                            'default' => 1,
                            'md' => 2,
                            'xl' => 3,
                        ])
                            ->columnSpanFull()
                            ->schema([

                                Select::make('resident_status')
                                    ->label('Status Penduduk')
                                    ->required()
                                    ->live()
                                    ->default(
                                        ResidentStatus::ACTIVE->value
                                    )
                                    ->options([
                                        ResidentStatus::ACTIVE->value => 'Aktif',

                                        ResidentStatus::PINDAH->value => 'Pindah',

                                        ResidentStatus::MENINGGAL->value => 'Meninggal',
                                    ])
                                    ->native(false)
                                    ->placeholder(
                                        'Pilih status penduduk'
                                    ),
                            ]),

                        /*
                         * ----------------------------------------------------
                         * STATUS PINDAH
                         * ----------------------------------------------------
                         */

                        Grid::make([
                            'default' => 1,
                            'md' => 2,
                        ])
                            ->columnSpanFull()
                            ->schema([

                                DatePicker::make('moved_at')
                                    ->label('Tanggal Pindah')
                                    ->native(false)
                                    ->displayFormat('d M Y')
                                    ->maxDate(now())
                                    ->required(
                                        fn (Get $get): bool => $get('resident_status') ===
                                            ResidentStatus::PINDAH->value
                                    )
                                    ->visible(
                                        fn (Get $get): bool => $get('resident_status') ===
                                            ResidentStatus::PINDAH->value
                                    ),

                                TextInput::make('moved_destination')
                                    ->label('Tujuan Pindah')
                                    ->maxLength(255)
                                    ->placeholder(
                                        'Contoh: Kota Makassar'
                                    )
                                    ->visible(
                                        fn (Get $get): bool => $get('resident_status') ===
                                            ResidentStatus::PINDAH->value
                                    ),
                            ]),

                        Textarea::make('moved_note')
                            ->label('Catatan Kepindahan')
                            ->rows(3)
                            ->columnSpanFull()
                            ->placeholder(
                                'Tambahkan keterangan jika diperlukan...'
                            )
                            ->visible(
                                fn (Get $get): bool => $get('resident_status') ===
                                    ResidentStatus::PINDAH->value
                            ),

                        /*
                         * ----------------------------------------------------
                         * STATUS MENINGGAL
                         * ----------------------------------------------------
                         */

                        Grid::make([
                            'default' => 1,
                            'md' => 2,
                        ])
                            ->columnSpanFull()
                            ->schema([

                                DatePicker::make('deceased_at')
                                    ->label('Tanggal Meninggal')
                                    ->native(false)
                                    ->displayFormat('d M Y')
                                    ->maxDate(now())
                                    ->required(
                                        fn (Get $get): bool => $get('resident_status') ===
                                            ResidentStatus::MENINGGAL->value
                                    )
                                    ->visible(
                                        fn (Get $get): bool => $get('resident_status') ===
                                            ResidentStatus::MENINGGAL->value
                                    ),

                                Textarea::make('deceased_note')
                                    ->label('Catatan Meninggal')
                                    ->rows(3)
                                    ->placeholder(
                                        'Keterangan tambahan jika diperlukan...'
                                    )
                                    ->visible(
                                        fn (Get $get): bool => $get('resident_status') ===
                                            ResidentStatus::MENINGGAL->value
                                    ),
                            ]),
                    ])
                    ->collapsible(),

                /*
                 * ============================================================
                 * 5. CATATAN
                 * ============================================================
                 */

                Section::make('Catatan Tambahan')
                    ->description(
                        'Informasi tambahan yang tidak termasuk dalam data utama.'
                    )
                    ->columnSpanFull()
                    ->schema([

                        Textarea::make('notes')
                            ->label('Catatan')
                            ->rows(4)
                            ->columnSpanFull()
                            ->placeholder(
                                'Masukkan catatan tambahan jika diperlukan...'
                            ),
                    ])
                    ->collapsible(),
            ]);
    }
}
