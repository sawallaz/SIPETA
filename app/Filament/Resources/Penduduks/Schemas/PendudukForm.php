<?php

namespace App\Filament\Resources\Penduduks\Schemas;

use App\Enums\BloodType;
use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\ResidentStatus;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PendudukForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identitas')
                    ->description('Data identitas dasar penduduk.')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('nik')
                                    ->label('NIK')
                                    ->required()
                                    ->unique('penduduk', 'nik', ignoreRecord: true)
                                    ->maxLength(16)
                                    ->regex('/^[0-9]{16}$/')
                                    ->helperText('Nomor Induk Kependudukan, 16 digit angka.'),
                                TextInput::make('full_name')
                                    ->label('Nama Lengkap')
                                    ->required()
                                    ->maxLength(255),
                            ]),
                        Grid::make(2)
                            ->schema([
                                TextInput::make('birth_place')
                                    ->label('Tempat Lahir')
                                    ->required()
                                    ->maxLength(255),
                                DatePicker::make('birth_date')
                                    ->label('Tanggal Lahir')
                                    ->required()
                                    ->native(false)
                                    ->displayFormat('d M Y')
                                    ->maxDate(now())
                                    ->helperText('Usia dihitung otomatis dan tidak disimpan.'),
                            ]),
                        Grid::make(2)
                            ->schema([
                                Select::make('gender')
                                    ->label('Jenis Kelamin')
                                    ->required()
                                    ->options([
                                        Gender::LAKI_LAKI->value => 'Laki-laki',
                                        Gender::PEREMPUAN->value => 'Perempuan',
                                    ]),
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
                                    ]),
                            ]),
                    ]),

                Section::make('Kartu Keluarga & Wilayah')
                    ->description('Keanggotaan Kartu Keluarga dan domisili RT.')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Select::make('kk_id')
                                    ->label('Kartu Keluarga')
                                    ->relationship('kartuKeluarga', 'kk_number')
                                    ->required()
                                    ->searchable()
                                    ->preload(),
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
                                    ]),
                            ]),
                        Select::make('rt_id')
                            ->label('RT')
                            ->relationship('rt', 'number')
                            ->required()
                            ->searchable()
                            ->preload(),
                    ]),

                Section::make('Data Sosial')
                    ->description('Agama, pendidikan, pekerjaan dan status perkawinan.')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Select::make('religion_id')
                                    ->label('Agama')
                                    ->relationship('religion', 'name')
                                    ->required()
                                    ->searchable()
                                    ->preload(),
                                Select::make('education_id')
                                    ->label('Pendidikan')
                                    ->relationship('education', 'name')
                                    ->required()
                                    ->searchable()
                                    ->preload(),
                            ]),
                        Grid::make(2)
                            ->schema([
                                Select::make('occupation_id')
                                    ->label('Pekerjaan')
                                    ->relationship('occupation', 'name')
                                    ->required()
                                    ->searchable()
                                    ->preload(),
                                Select::make('marital_status')
                                    ->label('Status Perkawinan')
                                    ->required()
                                    ->options([
                                        MaritalStatus::BELUM_KAWIN->value => 'Belum Kawin',
                                        MaritalStatus::KAWIN->value => 'Kawin',
                                        MaritalStatus::CERAI_HIDUP->value => 'Cerai Hidup',
                                        MaritalStatus::CERAI_MATI->value => 'Cerai Mati',
                                    ]),
                            ]),
                    ]),

                Section::make('Status Kependudukan')
                    ->description('Status aktif, kepindahan atau kematian.')
                    ->schema([
                        Select::make('resident_status')
                            ->label('Status Penduduk')
                            ->required()
                            ->live()
                            ->default(ResidentStatus::ACTIVE->value)
                            ->options([
                                ResidentStatus::ACTIVE->value => 'Aktif',
                                ResidentStatus::PINDAH->value => 'Pindah',
                                ResidentStatus::MENINGGAL->value => 'Meninggal',
                            ]),
                        Grid::make(2)
                            ->schema([
                                DatePicker::make('moved_at')
                                    ->label('Tanggal Pindah')
                                    ->native(false)
                                    ->displayFormat('d M Y')
                                    ->required(fn (Get $get): bool => $get('resident_status') === ResidentStatus::PINDAH->value),
                                TextInput::make('moved_destination')
                                    ->label('Tujuan Pindah')
                                    ->maxLength(255),
                            ])
                            ->visible(fn (Get $get): bool => $get('resident_status') === ResidentStatus::PINDAH->value),
                        Textarea::make('moved_note')
                            ->label('Catatan Pindah')
                            ->rows(3)
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => $get('resident_status') === ResidentStatus::PINDAH->value),
                        Grid::make(2)
                            ->schema([
                                DatePicker::make('deceased_at')
                                    ->label('Tanggal Meninggal')
                                    ->native(false)
                                    ->displayFormat('d M Y')
                                    ->required(fn (Get $get): bool => $get('resident_status') === ResidentStatus::MENINGGAL->value),
                                Textarea::make('deceased_note')
                                    ->label('Catatan Meninggal')
                                    ->rows(3),
                            ])
                            ->visible(fn (Get $get): bool => $get('resident_status') === ResidentStatus::MENINGGAL->value),
                    ]),

                Section::make('Catatan')
                    ->schema([
                        Textarea::make('notes')
                            ->label('Catatan')
                            ->rows(3)
                            ->columnSpanFull()
                            ->helperText('Catatan tambahan (opsional).'),
                    ]),
            ]);
    }
}
